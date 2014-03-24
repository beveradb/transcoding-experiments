<?php

namespace AB\Bundle\TranscodingExperimentsBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Debug\Debug;
use Symfony\Component\Debug\ErrorHandler;
use Symfony\Component\Debug\ExceptionHandler;
use Symfony\Component\ClassLoader\DebugClassLoader;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Process\Process;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Zend\Json\Expr;
use \DateTime;
use FFMpeg;
use AB\Bundle\TranscodingExperimentsBundle\Entity\Client;
use AB\Bundle\TranscodingExperimentsBundle\Entity\TranscodeProcess;

class DefaultController extends Controller
{
    public function indexAction()
    {
        return $this->render('ABTranscodingExperimentsBundle:Default:index.html.twig', array());
    }

	// Load in client IP, requested video and bitrate data, generate tailor made stream and start streaming it
    public function transcodeAction($clientBitrate, $inputFilepath, $containerFormat)
    {	
		Debug::enable();
		ErrorHandler::register();
		ExceptionHandler::register();
		DebugClassLoader::enable();

		// Set up entity access
		$em = $this->getDoctrine()->getManager();
		$transcodeProcessRepository = $em->getRepository('ABTranscodingExperimentsBundle:TranscodeProcess');

		// Set up logging
		$logger = $this->get('monolog.logger.transcode');
    	
		$inputFilepath = rawurldecode($inputFilepath);
		$clientIP = $this->container->get('request')->getClientIp();
		$logger->debug("transcodeAction(): Client GET request from IP: $clientIP for filepath $inputFilepath transcoded to $containerFormat with $clientBitrate KB/s bandwidth available");
		
		// Symfony likes everything to be RESTful, so let's create HTTP headers the Symfony way
		$response = new Response();
        
		// Stop PHP from timing out on long reads
		set_time_limit(0);
        
		// Get fingerprint data (or just a cookie) and basic identification such as IP address from the client, pass it to getClient()
		// getClient will attempt to find an existing client entity to match up with, or create a new one if it doesn't exist.
		$client = $this->getClient(array(
			'ip' => $clientIP
		));
		$clientID = $client->getId();
		
		// Check if any running transcodes are owned by this client.
		$query = $transcodeProcessRepository->createQueryBuilder('tp')
			->where('tp.clientID = :clientID')
			->andWhere('tp.status = :status')
			->setParameter('clientID', $clientID )
			->setParameter('status', 'running' )
			->getQuery();
		$clientRunningTranscodes = $query->getResult();
		$logger->debug("transcodeAction(): Client has ".count($clientRunningTranscodes)." running transcodes in the database");
		
		// The client is not requesting a byte range, so we'll assume they aren't already streaming a video, and go through the "new stream" logic.
		if (isset($_SERVER['HTTP_RANGE']) == false) {
            $logger->debug("transcodeAction(): Client has not requested a byte range so we're assuming this is a new transcode");

            // Tell the browser to request ranges from us in bytes
            $logger->debug("transcodeAction(): Sending client Accept-Ranges header to let them know they can seek");
            $response->headers->set("Accept-Ranges", "bytes");
		
			// Build absolute path to input video file, from Symfony application root directory and hard-coded video folder.
			// This should be made smarter to take full filepaths into account if supplied by client.
			//$inputFilepath = $this->get('kernel')->getRootDir() . 
				"/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/videos/$inputFilepath"; 
			$inputFilepath = "/home/andrew/Other/transcoding-experiments/web/bundles/abtranscodingexperiments/videos/$inputFilepath";
			$logger->debug("transcodeAction(): Input file path: $inputFilepath");
			
			// If any running transcodes belong to this client, we can assume the client has reloaded their browser 
			// or clicked another option, since we haven't got any byte range headers. As such, kill these transcode processes as they aren't being watched.
			foreach( $clientRunningTranscodes as $runningTranscode ) {
				$runningTranscodePID = $runningTranscode->getProcessPID();
				$killProcess = new Process("kill -9 $runningTranscodePID");
				$killProcess->run();
				$killOutput = $killProcess->getOutput(); 
				if(strpos($killOutput, 'no such process') !== false) {
					$runningTranscode->setStatus('ended');
                    $logger->debug("transcodeAction(): Updated transcode table since transcode {$runningTranscode->getId()} with PID $runningTranscodePID has ended");
				} else {
					$runningTranscode->setStatus('killed');
                    $logger->debug("transcodeAction(): Killed running transcode with PID $runningTranscodePID");
				}	
			}
			$em->flush();
				
			// Now we've cleaned up and killed old processes, let's create a new transcode process for this client!
			//$clientBitrate = $clientBitrate * 0.1;
			$transcodeProcess = $this->createTranscodeProcess($clientID, $clientBitrate, $inputFilepath, $containerFormat);
		} else {
			// If the client is requesting a byte range, they must be actually streaming a video already. 
			// As such, we don't want to be killing transcodes and starting new ones, we just want to continue with out outputStream() logic.
			// To do that, though, we need to find the client's currently running transcode process to be able to pass that entity to outputStream()
			if( count($clientRunningTranscodes) != 1 ) {
				$logger->error("transcodeAction(): Client requested byte range, but has 0 running transcodes?");
				die();
			}
			// We've got a single running transcode for this client. Great! Continue streaming it.
            $logger->debug("transcodeAction(): Client requested byte range: {$_SERVER['HTTP_RANGE']} and has a single running transcode");
			$transcodeProcess = $clientRunningTranscodes[0];
		}		
		
		// Get output file path
		$outputFilepath = $transcodeProcess->getOutputFilepath();
		// Process PID, for keeping track of transcode
		$pid = $transcodeProcess->getProcessPID();
		
		// Output appropriate content type header
		if($containerFormat == "webm") {
			$response->headers->set('Content-Type', 'video/webm');
		} elseif($containerFormat == "flv") {
			$response->headers->set('Content-Type', 'video/x-flv');
		} else {
			$response->headers->set('Content-Type', 'video/mp4');
		}
        //$response->headers->set('Content-Type', 'text/html');
        
		
		// File Open Loop: Try opening file until file is created - this is just in case the transcode process takes a while to start up
		// Keep track of how long we've been waiting
		$sleepTime = 0;
		while(1) {
			$fp = @fopen($outputFilepath, 'rb');
			if( $fp === FALSE ) {
				$logger->debug("transcodeAction(): Waiting for output file to be created. Time so far: $sleepTime seconds");
				if ( $sleepTime > 10 ) {
					$logger->error("transcodeAction(): Timed out waiting for more than 10 seconds for output file to be created; check transcode log for errors");
					die();
				}
				// Sleep for 0.01 seconds at a time between polling fopen for output file
				usleep(10000);
				$sleepTime+=0.01;
			} else {
				$logger->debug("transcodeAction(): Output file exists and is readable");
				break;
			}
		}
	
		// These values are totally fake and irrelevant, but they have to be big enough for the browser to essentially think we have unlimited data
		// Chances are we're never going to be trying to handle a transcode where the output is larger than 50GB...
		//$end 	= 49999999999;
		//$size = 50000000000; 
		//$end 	= 999999;
		//$size 	= 1000000;
		//$end 	= 409599;
		//$size 	= 409600;

		//$size = filesize($inputFilepath);
		$size = 276134947;
		$length = $size;
		$start = 0;		
		$end = $size-1;
		$logger->debug("transcodeAction(): Setup partial response variables; size: $size, length: $length, start: $start, end: $end");
		
		// Estimate the file size of the completed transcode. (KB/s * seconds) / 1024 = bytes
		//$estimatedSize = $transcodeProcess->getTargetBitrate() * $transcodeProcess->getDuration() / 1024;
		        
		// Client has requested a range - this means they have already started playback of the file and are telling us to send them some 
		// data starting at a specific start byte. This can mean the client is seeking, or just resuming after a network fail / pause.
		if (isset($_SERVER['HTTP_RANGE']) != false) {
			// $_SERVER['HTTP_RANGE'] is a string in a format such as (at the start of playback): "bytes=0-"
			// During playback, or upon seeking, it will probably have a start byte, such as: "bytes=276134889-"
			
            // Since we're starting from some specific point in the file, technically this is an HTTP 206, not that it makes any difference.
            $response->setStatusCode(Response::HTTP_PARTIAL_CONTENT);
            $logger->debug("transcodeAction(): Content range requested, adding Partial Content to headers");
			
            // Ditch the word "bytes" and the equals sign
			list(, $range) = explode('=', $_SERVER['HTTP_RANGE'], 2);
			
			// $range should now just be a string with the actual range requested by the client, such as "276134889-"
			
			// Some clients may try and request multiple ranges at the same time, using commas.
			// We don't need to implement that functionality, so just tell them we can't satisfy the range request and die.
			if (strpos($range, ',') !== false) {
				$logger->error("transcodeAction(): Client sent byte range request with a comma in it"); 
				die();
			}
			
			// Given most common range request format: "276134889-", this returns an array(2) { [0]=> "276134889", [1]=> "" }	
			$range = explode('-', $range);
			$requestedStart = $range[0];
			$logger->debug("transcodeAction(): Client requested range start: $requestedStart");
			// Unless the client requested an end byte (rare), treat it like the client wants everything up until the last byte
			$requestedEnd = (isset($range[1]) && is_numeric($range[1])) ? $range[1] : $end;
			// Sanitize the requested end byte to make sure it isn't after our end byte (even though it's all fake anyway)
			$requestedEnd = ($requestedEnd > $end) ? $end : $requestedEnd;
			// More range sanitization; if the client requested a start byte which is after the end byte they requested,
			// or either the requested start or end are outside our fake file boundaries, tell them we can't do that.
			if ($requestedStart > $requestedEnd || $requestedStart > $end || $requestedEnd > $end) {
				$logger->error("transcodeAction(): Client sent crazy byte range");
				die();
			}
			
			// The idea here is to get the length of the data we plan to send the client by the difference between the requested start and end bytes
			// However, firstly the client almost always doesn't specify an end byte anyway, so we end up with a length which is just
			// the file size minus the requested start byte. 
			// Whether or not the client actually does anything relevant to video playback with this value or not, I'm not sure.
			// All I know is that if they are, with our fake 50GB end byte this length is going to be huge and wildly incorrect
			$length = $requestedEnd - $requestedStart;
			
			// This is the only important bit in this if block - it moves the file pointer to the start position so we can read data from there
			// However we can't just move the file pointer to any arbitrary position, we first need to check whether that byte has even been written yet
			// by the transcode process. If it hasn't, we just have to delay sending any data until the data has been written.
			$sleepTime = 0;
			while(1) {
				// Clear filesize cache
				clearstatcache();
				$logger->debug("transcodeAction(): Inside byte range loop, checking for data in output file to satisfy start byte");
				if( $this->dataExists($outputFilepath, $requestedStart) === false ) {
					
					$logger->debug("transcodeAction(): Inside byte range loop, waiting for data to exist at byte: $requestedStart. Current output filesize: ".var_export( filesize($outputFilepath), true ) );
					if ( $sleepTime > 600 ) {
						$logger->error("transcodeAction(): Inside byte range loop, timed out waiting for more than 10 minutes for transcode to write $requestedStart data");
						die();
					}
					// Sleep for 1 second at a time between checking output filesize
					usleep(1000000);
					$sleepTime+=1;
				} else {
					$logger->debug("transcodeAction(): Inside byte range loop, found data at requested start byte, seeking to $requestedStart. ");
					fseek($fp, $requestedStart);
					break;
				}
			}
		}
		
		// The content range is the same every time we tell the client, and it's always these pointless fake values to make the client keep requesting
		$response->headers->set("Content-Range", "bytes $start-$end/$size");
		// Hopefully this was set to something sane above, but it's basically irrelevant since we're using fake values.
		$response->headers->set("Content-Length", "$length");
		
		// Send client the headers before we start sending binary data
		$response->sendHeaders();
		
		// Ok, now we're finally at the meat of this function. We've got a file pointer to an open video file, 
		// in theory we just need to read data from the file and send it to the client. Since PHP doesn't echo anything immediately, we do this
		// in Large chunks and flush them to the client in every loop iteration.
		// However, we also need to check whether we actually have Large of data to send to the client first, and delay until we do.
		
		// Large Buffer Loop: Wait until transcode has written chunk of new data, then stream it in smaller chunks
		$bigbuffer = 163840; // 20KB ish
		while(1) {
			$sleepTime = 0;
			// Clear filesize cache
			clearstatcache();
			$bigposition = ftell($fp);
			if ( $bigposition === false ) {
				$logger->error("transcodeAction(): Inside Large buffer loop, ftell returned false so we must have reached the end of the file");
				die();
			}
			
			$logger->debug("transcodeAction(): Inside Large buffer loop, about to check for $bigbuffer data in output file");
			if( $this->dataExists($outputFilepath, $bigposition+$bigbuffer+1) === false ) {
				// If there is no new data and the transcode process has finished, stop streaming.
				$pidCheck = $this->checkProcess('ffmpeg', $pid);
				if( $pidCheck === false ) {
					$logger->debug("transcodeAction(): Transcode process with ID: ".$transcodeProcess->getId()." and PID: $pid seems to have finished. Successfully streamed $bigposition bits of data.");
					break;
				}
				
				$logger->debug("transcodeAction(): Inside Large buffer loop, waiting for $bigbuffer bytes of new data to exist. Current output filesize: ".var_export( filesize($outputFilepath), true ) );
				if ( $sleepTime > 60 ) {
					$logger->error("transcodeAction(): Inside Large buffer loop, timed out waiting for more than a minute for transcode to write $bigbuffer data");
					die();
				}
				// Sleep for 1 second at a time between checking output filesize
				usleep(1000000);
				$sleepTime+=1;
			} else {
				$logger->debug("transcodeAction(): Inside Large buffer loop, found $bigbuffer bytes of new data to stream, starting 1KB loop");
				
				// Read data to the client in 1KB chunks.
				$smallbuffer = 8192;
				while(1) {
					$smallposition = ftell($fp);
					if ( $smallposition === false ) {
						$logger->error("transcodeAction(): Inside 1KB buffer loop, ftell returned false so something must have gone wrong");
						die();
					}
					
					if ( $smallposition > $length ) {
						$logger->error("transcodeAction(): Inside 1KB buffer loop, our position is greater than the length we claimed so stop streaming");
						die();
					}
					
					// Check if our file pointer has reached the end of the bigbuffer part of the file
					if($smallposition >= $bigposition+$bigbuffer) {
						$logger->debug("transcodeAction(): Inside 1KB buffer loop, smallposition is gte bigbuffer so we have finished streaming a Large chunk");
						break;
					}
					echo fread($fp, $smallbuffer);
					flush();
				}
			}
		}
		
		return $response;
    }
	
	// Get basic data from client, find existing Client entity in database or create new one
	// In future this should check more than just IP address; cookies and possibly browser fingerprinting should be used to track clients
	public function getClient($fingerprintArray)
	{
		$em = $this->getDoctrine()->getManager();
		$client = $em->getRepository('ABTranscodingExperimentsBundle:Client')->findOneByIp($fingerprintArray['ip']);
		if( empty($client) ) {
			$client = new Client(); 
            $client->setIp($fingerprintArray['ip']);
			$em->persist($client);
			$em->flush();
        }

		return $client;
	}
	
	// Choose transcoding parameters based on input file and client bitrate capability, create TranscodeProcess entity in database and start process
	public function createTranscodeProcess($clientID, $clientBitrate, $inputFilepath, $containerFormat, $startSeconds = null)
	{
		// Set up logging
		$logger = $this->get('monolog.logger.transcode');
		
		$em = $this->getDoctrine()->getManager();
		$transcodeDirectory = $this->container->getParameter('kernel.cache_dir') . '/ABTranscodingExperimentsBundle/';
		
		$fs = new Filesystem();
		if( $fs->exists($transcodeDirectory) === false ) {
			try {
				$fs->mkdir($transcodeDirectory);
			} catch (IOException $e) {
				echo "An error occurred while attempting to create temporary directory $transcodeDirectory";
			}
		}
	
		// Get path to statically built FFmpeg binary in resources directory. This should be re-built with build.sh for target platform.
		$ffmpegPath = $this->get('kernel')->getRootDir().
			"/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffmpeg";			
		$ffprobePath = $this->get('kernel')->getRootDir().
			"/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffprobe";
		
		$ffprobe = FFMpeg\FFProbe::create(array(
			'ffmpeg.binaries'  => $ffmpegPath,
			'ffprobe.binaries' => $ffprobePath
		));
		// Extract input file duration in seconds, using FFprobe
		$duration = 6000;//round($ffprobe->streams($inputFilepath)->videos()->first()->get('duration'),2);
		// Get input bitrate in KB/s so we don't do anything stupid like transcode to a higher bitrate
		$inputBitrate = round($ffprobe->streams($inputFilepath)->videos()->first()->get('bit_rate') / 8192, 2);
		$inputCodec = $ffprobe->streams($inputFilepath)->videos()->first()->get('codec_name');
		
		$logger->debug("Input file codec: $inputCodec, bitrate: $inputBitrate KB/s" );
		
		// If the input file already has a bitrate which is lower than our client's bandwidth, encode to match that
		$targetBitrate = $inputBitrate*2 < $clientBitrate ? $inputBitrate*2 : $clientBitrate;
		$logger->debug("Using bitrate: $targetBitrate KB/s for output file" );
		
		// Container format; should be mp4 or webm depending on client.
		switch ($containerFormat) {
			case 'webm':
				// WebM is basically Matroska but royalty-free
				$format = 'webm';
				// Build video codec options
				$Vcodec = "-c:v libvpx -b:v {$targetBitrate}K -deadline good -slices 8 -cpu-used 5";
				// Fairly low bitrate but decent enough quality for most purposes audio; using fdk_aac
				$Acodec = "-c:a libvorbis -qscale:a 3";
				
				
				// OVERWRITING ABOVE TO TEST IF H264 IN MKV CONTAINER STREAMED AS IF IT IS WEBM WORKS
				//$format = 'matroska';
				//$Vcodec = "-c:v libx264 -b:v {$targetBitrate}K ";
				//$Acodec = "-c:a libfdk_aac -vbr 3 -ac 2";
			break;
			case 'flv':
			default:
				// FLV is Flash Video; usually only has VP6 video in it, but we're going to stuff h.264 in it for streaming to Flash
				$format = 'flv';
				
				// x264 Options
				// crf			# Quality factor. Lower is better quality, filesize increases exponentially. Sane range is 18-30
				// preset		# One of: ultrafast,superfast, veryfast, faster, fast, medium, slow, slower, veryslow or placebo
				// profile		# One of: baseline, main, high, high10, high422 or high444 
				// tune			# One of: film animation grain stillimage psnr ssim fastdecode zerolatency 
				// No need to vary these yet; may need to lower profile for certain mobile devices.
				$profile = 'high';
				$tune = 'zerolatency';
				
				// Choose optimal encoding settings for various bitrate amounts; these best fits were calculated for animated movies.
				if($targetBitrate > 200) {
					// 200 KBps = 1.5 Mbps. Transcode to 1080p@CRF25-medium.
					$resolution = '1920x1080'; $crf = '25'; $preset = 'medium';
				} elseif($targetBitrate > 130) {
					// 130 KBps = 1.0 Mbps. Transcode to 1080p@CRF30-medium.
					$resolution = '1920x1080'; $crf = '30'; $preset = 'medium';
				} elseif($targetBitrate > 65) {
					// 65 KBps = 0.5 Mbps. Transcode to 1080p@CRF35-medium.
					$resolution = '1920x1080'; $crf = '35'; $preset = 'medium';
				} else {
					// Bitrate < 65 KBps. Transcode to 480p@CRF30-veryslow.
					$resolution = '720x480'; $crf = '30'; $preset = 'veryslow';
				}
				// Build video codec options
				//$Vcodec = "-c:v libx264 -preset $preset -tune $tune -crf $crf -profile:v $profile -s $resolution";
				$Vcodec = "-c:v libx264 -b:v {$targetBitrate}K ";
				
				if( $inputCodec == "h264" && ($inputBitrate < $targetBitrate) ) {
					$logger->debug("Input file is h264 and has a lower video bitrate than client bandwidth, using c:v copy" );
					$Vcodec = "-c:v copy ";
				}
				
				// Fairly low bitrate but decent enough quality for most purposes audio; using fdk_aac
				$Acodec = "-c:a libfdk_aac -vbr 3 -ac 2";		
		}
		
		
		// Write TranscodeProcess entity to database immediately so we can get an ID to add to the output filename
		// Has fields: format bitrate status fullCommand processPID
		$transcodeProcess = new TranscodeProcess(); 
		$transcodeProcess->setStatus('running');
		$transcodeProcess->setClientID($clientID);
		$transcodeProcess->setTargetBitrate($targetBitrate);
		$transcodeProcess->setInputFilepath($inputFilepath);
		$transcodeProcess->setDuration($duration);
		$em->persist($transcodeProcess);
		$em->flush();
		$transcodeProcessID = $transcodeProcess->getId();
		$logger->debug("TranscodeProcess entity created with ID: $transcodeProcessID" );
		
		$outputFilename = date('Y-m-d_H.i.s') .'_'. $transcodeProcessID .'.'. $containerFormat;
		$outputFilepath = $transcodeDirectory.$outputFilename;
		$commandOutputFile = $outputFilepath . '.log';
		
		$transcodeProcess->setOutputFilename($outputFilename);
		$transcodeProcess->setOutputFilepath($outputFilepath);
		
		// For now, simply add all subtitles from input file to output. This only works with the MKV container.
		//$subtitles = "-c:s copy";
		$subtitles = "";
		
		// Seek to a certain point in the file
		$startTime = $startSeconds ? " -ss $startSeconds " : '';
		
		// Use 8 threads as almost all servers have at least this many, and the benefits with more anyway.
		$fullCommand = "$ffmpegPath -threads 14 $startTime -i \"$inputFilepath\" $Vcodec $Acodec $subtitles -f $format -y \"$outputFilepath\" > \"$commandOutputFile\" 2>&1";
		$transcodeProcess->setFullCommand($fullCommand);
		
		// Actually start the process
		$process = new Process($fullCommand);
		$process->start();
		// Since the PID returned is actually the php fork which disappears, the FFmpeg PID we want is just one greater
		$pid = $process->getPid() + 1;
		$transcodeProcess->setProcessPID($pid);
		// Write final TranscodeProcess entity to the database
		$em->flush();
		
		$logger->debug("Transcode process started, pid: $pid" );
		return $transcodeProcess;
	}

	public function checkProcess($processName, $pid) 
	{
		// Set up logging
		$logger = $this->get('monolog.logger.transcode');
		// Launch pidof to look for process
		$pidofProcess = new Process("pidof $processName");
		$pidofProcess->run();
		$pidofOutput = $pidofProcess->getOutput(); 
		
		$check = strpos($pidofOutput, "$pid");
		$logger->debug("checkProcess(): strpos returned: ".var_export($check,true)." on the output from pidof: ".trim($pidofOutput)  );
		
		if( $check === false ) {
			$logger->debug("checkProcess(): Process '$processName' with PID $pid was not found");
			return false;
		}
		$logger->debug("checkProcess(): Process '$processName' with PID $pid is still running");
		return true;
	}
	
	public function dataExists($filepath, $bytes)
	{
		clearstatcache(); // Clear filesize cache
		if( filesize($filepath) > $bytes ) {
			return true;
		}
		return false;
	}
	
	// Send the browser a 1MB (or larger if needed) file to test the connection speed
    public function servePayloadAction($lengthKB, $startTime)
    {
		$response = new Response();
		$filename = $this->get('kernel')->getRootDir()."/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/payload/5MB.bin";
		$response->headers->set('Cache-Control', 'private');
		$response->headers->set('Content-type', 'application/octet-stream');
		$response->headers->set('Content-length', $lengthKB*1000);
		
		$d = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'payload'. $lengthKB*1000 .'.bin');
		$response->headers->set('Content-Disposition', $d);
		
		$response->sendHeaders();
		return $response->setContent(file_get_contents($filename, false, null, -1, $lengthKB*1000));
    }
	
}
