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

//$absoluteFilepath = "/home/andrew/Other/transcoding-experiments/web/bundles/abtranscodingexperiments/videos/bbb30.webm";

class DefaultController extends Controller
{
    public function indexAction()
    {
        return $this->render('ABTranscodingExperimentsBundle:Default:index.html.twig', array());
    }

    public function loadFileAction($inputFilepath)
    {
        return $this->render('ABTranscodingExperimentsBundle:Default:stream.html.twig',
            array(
                'inputFilepath' => rawurldecode($inputFilepath)
            ));
    }

    // Load in client IP, requested video and bitrate data, generate tailor made stream and start streaming it
    public function transcodeAction($clientBitrate, $inputFilepath, $startSecond, $containerFormat)
    {
        // Enable debugging code
        $static = true;
        
        // Set up Symfony debugging
        Debug::enable();
        ErrorHandler::register();
        ExceptionHandler::register();
        // Debug errors by showing them in HTML despite supposedly being a video stream
        //$containerFormat = "html";

        // Set up Monolog logging to channel "transcode"
        $transcodeLog = $this->get('monolog.logger.transcode');
        $transcodeRangeRequestLog = $this->get('monolog.logger.transcode_range_request');

        // Stop PHP from timing out on long reads
        set_time_limit(0);

        // Get entity manager and Transcode entity repository
        $em = $this->getDoctrine()->getManager();
        $transcodeProcessRepository = $em->getRepository('ABTranscodingExperimentsBundle:TranscodeProcess');

        // Start looking at / parsing request variables and headers
        $inputFilepath = rawurldecode($inputFilepath);
        $clientIP = $this->container->get('request')->getClientIp();
        $clientBitrate = intval($clientBitrate);
        $transcodeLog->info("\n\n\n\n\ntranscodeAction(): Client GET request from IP: $clientIP for filepath $inputFilepath transcoded to $containerFormat with $clientBitrate KB/s bandwidth available");
        
        // Load static output file
        if($static) {
            $staticFilename = pathinfo($inputFilepath, PATHINFO_FILENAME);
            if($clientBitrate > 1000) {
                $staticBitrate = 1000;
            } elseif($clientBitrate > 600) {
                $staticBitrate = 600;
            } elseif($clientBitrate > 400) {
                $staticBitrate = 400;
            } elseif($clientBitrate > 200) {
                $staticBitrate = 200;
            } elseif($clientBitrate > 100) {
                $staticBitrate = 100;
            } else {
                $staticBitrate = 50;
            }
            $staticFilepath = "/home/andrew/Other/transcoding-experiments/web/bundles/abtranscodingexperiments/videos/create-test-files/$staticFilename-static-$staticBitrate.$containerFormat";
            $staticfp = fopen($staticFilepath, 'rb');
            $staticstat = fstat($staticfp);
            $staticSize = $staticstat['size'];
            $transcodeLog->info("\n\n\n\n\ntranscodeAction(): Static file loaded: $staticFilepath");
        }

        // Check if byte range request was made and parse to get rangeStart and rangeEnd values
        $rangeRequested = isset($_SERVER['HTTP_RANGE']) ? true : false;
        preg_match('/^bytes=(\d*)-(\d*)$/', @$_SERVER['HTTP_RANGE'], $rangeMatches);
        $rangeStart = intval(@$rangeMatches[1]);
        $rangeEnd = intval(@$rangeMatches[2]);
        $transcodeLog->info("transcodeAction(): Client byte range requested: $rangeStart to $rangeEnd");
        $transcodeRangeRequestLog->info("transcodeAction(): Client byte range requested: $rangeStart to $rangeEnd");

        // Get fingerprint/cookie/IP address from the client, pass it to getClient()
        // getClient will attempt to find a matching client entity or create a new one
        $client = $this->getClient(array(
            'ip' => $clientIP
        ));
        $clientID = $client->getId();
        $transcodeLog->info("transcodeAction(): Client linked up with ID: $clientID");

        // If any running transcodes belong to this client, kill these transcode processes
        // Also update the database if any processes owned by this client have naturally ended
        $transcodeLog->info("transcodeAction(): Killing any running transcodes owned by this client");

        // Check if any running transcodes are owned by this client.
        $query = $transcodeProcessRepository->createQueryBuilder('tp')
            ->where('tp.clientID = :clientID')
            ->andWhere('tp.status = :status')
            ->setParameter('clientID', $clientID)
            ->setParameter('status', 'running')
            ->getQuery();
        $clientRunningTranscodes = $query->getResult();
        $transcodeLog->info("transcodeAction(): Client has " . count($clientRunningTranscodes) . " running transcode(s) in the database");

        // Loop through any running transcodes and kill/update them
        foreach ($clientRunningTranscodes as $runningTranscode) {
            $runningTranscodePID = $runningTranscode->getProcessPID();
            if ($this->killTranscodeProcess($runningTranscodePID)) {
                $runningTranscode->setStatus('killed');
                $transcodeLog->info("transcodeAction(): Killed running transcode with PID $runningTranscodePID");
            } else {
                $runningTranscode->setStatus('ended');
                $transcodeLog->info("transcodeAction(): Updated transcode table since transcode {$runningTranscode->getId()} with PID $runningTranscodePID has ended");
            }
        }
        $em->flush();

        // Build absolute path to input video file, from Symfony application root directory, hard-coded video folder and path requested by client
        $rootDirectory = $this->get('kernel')->getRootDir() . '/../';
        $videoDirectory = 'web/bundles/abtranscodingexperiments/videos/';
        $absoluteInputFilepath = $rootDirectory . $videoDirectory . $inputFilepath;
        $transcodeLog->info("transcodeAction(): Path to input video: {$videoDirectory}{$inputFilepath}");

        // Get path to statically built FFmpeg binary in resources directory. This should be re-built with build.sh for target platform.
        $ffprobe = FFMpeg\FFProbe::create(array(
            'ffmpeg.binaries' => $this->get('kernel')->getRootDir() .
                "/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffmpeg",
            'ffprobe.binaries' => $this->get('kernel')->getRootDir() .
                "/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffprobe"
        ));

        // Extract input file duration in seconds, using FFprobe
        $duration = round($ffprobe->format($absoluteInputFilepath)->get('duration'), 2);
        $transcodeLog->info("transcodeAction(): Extracted duration from input file: $duration seconds");

        // Estimate the file size of the completed transcode using client bandwidth and input duration. (KB/s * seconds) * 1024 = bytes
        $estimatedOutputSize = round($clientBitrate * $duration * 1024);
        if($static) $estimatedOutputSize = $staticSize;
        $transcodeLog->info("transcodeAction(): Calculated estimate size for output file: $estimatedOutputSize bytes");
        
        // Now we've parsed all our input variables and got a running transcode, let's start streaming
        $response = new Response();
        $transcodeLog->info("transcodeAction(): Cleaned up old transcodes and matched client; Building response");

        // Set HTTP version to 1.1
        $response->setProtocolVersion('1.1');
        $transcodeLog->info("transcodeAction(): Setting HTTP version to 1.1");

        // Check byte range for sanity
        if ($rangeRequested) {
            if ($rangeStart > $estimatedOutputSize) {
                $transcodeRangeRequestLog->info("transcodeAction(): Client requested range start of $rangeStart which is > file size, sending HTTP 416");

                // Tell the client that range was stupid and we don't like it
                $response->setStatusCode(Response::HTTP_REQUESTED_RANGE_NOT_SATISFIABLE);
                $transcodeLog->info("transcodeAction(): Setting response code to 416 Requested Range Not Satisfiable");

                // Tell client the theoretical range of bytes it can request
                $response->headers->set("Content-Range", "bytes */$estimatedOutputSize");
                $transcodeLog->info("transcodeAction(): Adding header: Content-Range: bytes */$estimatedOutputSize and exiting");

                return $response;
            }
            
            // Since we're technically responding with part of the file, it's an HTTP 206
            if($containerFormat !== "flv") {
                $response->setStatusCode(Response::HTTP_PARTIAL_CONTENT);
                $transcodeLog->info("transcodeAction(): Setting response code to 206 Partial Content");
            }
        }

        // This header should go along with all responses to reassure client they can seek, unless flv
        if ($containerFormat == "flv") {
            $response->headers->set("Accept-Ranges", "none");
            $transcodeLog->info("transcodeAction(): Adding Accept-Ranges: none header to stop seeking");
        } else {
            $response->headers->set("Accept-Ranges", "bytes");
            $transcodeLog->info("transcodeAction(): Adding header: Accept-Ranges to allow seeking");
        }
        
        // If the end byte is set to 0, we take that to mean "read from rangeStart up till the last byte"
        $rangeEnd = $rangeEnd ? $rangeEnd : $estimatedOutputSize - 1;
        
        // Make single-byte requests work
        if ($rangeStart == $rangeEnd) {
            $transcodeLog->info("transcodeAction(): Client requested range which starts and ends at the same byte: $rangeStart so we're decrementing rangeStart");
            $rangeStart--;
        }

        // In theory the amount of data we intend to sent is the end minus start byte, but rangeEnd is 0-indexed
        $length = $rangeEnd - $rangeStart + 1;

        $response->headers->set("Content-Length", "$length");
        $transcodeLog->info("transcodeAction(): Adding header: Content-Length: $length");

        // This tells the browser the theoretical range of bytes it can request
        if($containerFormat != "flv") {
            $response->headers->set("Content-Range", "bytes $rangeStart-$rangeEnd/$estimatedOutputSize");
            $transcodeLog->info("transcodeAction(): Adding header: Content-Range: bytes $rangeStart-$rangeEnd/$estimatedOutputSize");
        }

        // Follow apache as closely as possible
        $response->headers->set('Connection', 'Keep-Alive');
        $transcodeLog->info("transcodeAction(): Adding header: Connection: Keep-Alive");

        // Follow apache as closely as possible, stop incorrect caching etc.
        $etag = md5($absoluteInputFilepath.$clientBitrate);
        $response->headers->set('ETag', $etag);
        $transcodeLog->info("transcodeAction(): Adding header: ETag: $etag");

        // Output appropriate content type header
        if ($containerFormat == "webm") {
            $response->headers->set('Content-Type', 'video/webm');
        } elseif ($containerFormat == "mp4") {
            $response->headers->set('Content-Type', 'video/mp4');
        } elseif ($containerFormat == "flv") {
            $response->headers->set('Content-Type', 'video/x-flv');
        } else {
            // This is just for debugging, so we can more easily see symfony errors
            $response->headers->set('Content-Type', 'text/html');
        }
        $transcodeLog->info("transcodeAction(): Adding header: Content-Type for $containerFormat video stream");

        // Send client the headers before we start sending binary data
        $response->sendHeaders();
        $transcodeLog->info("transcodeAction(): Sending headers to client, ready to start stream");

        // Calculate start point for transcode based on range request
        $startSecond = $this->getTranscodeStartSecond($rangeStart, $estimatedOutputSize, $duration);
        $transcodeLog->info("transcodeAction(): Calculated startSecond: $startSecond from rangeStart: $rangeStart ");

        // Now we've cleaned up and killed old processes, let's create a new transcode process
        $transcodeProcess = $this->createTranscodeProcess($clientID, $clientBitrate, $absoluteInputFilepath, $containerFormat, $startSecond);
        // Get output file path
        $outputFilepath = $transcodeProcess->getOutputFilepath();
        // Process PID, for keeping track of transcode
        $pid = $transcodeProcess->getProcessPID();

        // Try opening file until file is created - this is just in case the transcode process takes a while to start up
        // Log how long we've been waiting, error if more than 10 seconds
        $sleepTime = 0;
        while (1) {
            $fp = @fopen($outputFilepath, 'rb');
            if($static) $fp = $staticfp;
            if ($fp === FALSE) {
                $transcodeLog->debug("transcodeAction(): Waiting for output file to be created. Time so far: $sleepTime seconds");
                if ($sleepTime > 10) {
                    $transcodeLog->error("transcodeAction(): Timed out waiting for more than 10 seconds for output file to be created; check transcode log for errors");
                    die();
                }
                // Sleep for 0.01 seconds at a time between polling fopen for output file
                usleep(10000);
                $sleepTime += 0.01;
            } else {
                $transcodeLog->info("transcodeAction(): Output file exists and is readable");
                break;
            }
        }

        // Ok, now we're finally at the meat of this function. We've got a file pointer to an open video file, 
        // in theory we just need to read data from the file and send it to the client. Since PHP doesn't echo anything immediately, we do this
        // in Large chunks and flush them to the client in every loop iteration.
        // However, we also need to check whether we actually have Large of data to send to the client first, and delay until we do.

        // Stream data of exact length
        if($rangeRequested) {
            $transcodeLog->info("transcodeAction(): Processing client requested range: start $rangeStart, end $rangeEnd, length $length");
            $transcodeRangeRequestLog->info("transcodeAction(): Processing client requested range: start $rangeStart, end $rangeEnd, length $length");
        } else {
            $transcodeLog->info("transcodeAction(): No range requested by client, range: start $rangeStart, end $rangeEnd, length $length");
            $transcodeRangeRequestLog->info("transcodeAction(): No range requested by client, range: start $rangeStart, end $rangeEnd, length $length");
        }

        // Read initial data from output file
        if($static) {
            $transcodeLog->info("transcodeAction(): About to do a basic read of data in outputFilepath: $outputFilepath from $rangeStart to $rangeEnd");
            $this->readBufferedData($staticfp, $rangeStart, $rangeEnd);
            fclose($staticfp);
            exit;
        }

        // Transcode Buffer Loop: Send the requested amount of data to the client whilst waiting for transcode to actually write it
        // In a loop which ends when we've met the requested amount of data, wait until transcode has written a chunk of new data, then stream it
        $totalBytesSent = 0;
        while (1) {
            // Stop if we've sent more data than we were meant to
            // if($totalBytesSent >= $length) {
            // $transcodeLog->info("transcodeAction(): TotalBytesSent == length so we've finished sending the requested data. Killing PID $pid");
            // $this->killTranscodeProcess($pid);
            // break;
            // }

            $stat = fstat($fp);
            $outputSize = $stat['size'];
            $transcodeLog->debug("transcodeAction(): Inside transcode buffer loop, output filesize: $outputSize");

            // Wait for output file to have enough data to stream another chunk
            $sleepTime = 0;
            $minimumSize = $totalBytesSent + 40960;
            if ($outputSize < $minimumSize) {
                // If the output file hasn't met our minimum size for buffering yet, continue waiting till it has
                $pidCheck = $this->checkProcess('ffmpeg', $pid);
                if ($pidCheck === false) {
                    $transcodeLog->info("transcodeAction(): Transcode process with PID: $pid seems to have finished. Successfully streamed $totalBytesSent bytes.");
                    break;
                }

                $transcodeLog->debug("transcodeAction(): Inside transcode buffer loop, waiting for output size $outputSize to be > $minimumSize");
                if ($sleepTime > 60) {
                    $transcodeLog->error("transcodeAction(): Inside transcode buffer loop, timed out waiting for more than 60 seconds for transcode to write enough data");
                    break;
                }
                // Sleep for 1 second at a time between checking output filesize
                usleep(1000000);
                $sleepTime += 1;
                continue;
            }

            // We essentially want to read exactly $length bytes from the output file
            // But since we have to wait for the transcode to write data first, we can't just read it all at once
            // Instead we wait till a chunk of new data exists, read as much as we can and add the number of bytes read to $totalBytesSent
            // Then we iterate again, wait for more new data, read the new chunk starting where we left off and repeat until $totalBytesSent = $length


            // Calculate the bytes we will actually read from the output file
            //if( $outputSize < $length ) {
            // Output file is bigger than $bufferMin (otherwise we'd still be waiting), but less than the total desired length.
            // Read as much data as we already do have in the output file as fast as possible, our client is hungry!
            //$endByte = $outputSize - 1;
            //} elseif( $outputSize >= $length ) {
            // Output file is larger than the length requested by the client. We should be able to read the remaining amount in the range.
            //$endByte = $length;
            //}

            // Start at whatever position in the output file we're already at
            $startByte = $totalBytesSent;
            $endByte = $outputSize;

            // Amount of data we are reading this iteration is $endByte - $startByte
            // On first run this will likely be $outputSize - 0
            // We need to keep track of exactly how much data we have already sent 
            $dataToRead = $endByte - $startByte + 1;
            $totalBytesSent += $dataToRead;

            $transcodeLog->debug("transcodeAction(): Inside transcode buffer loop, reading $dataToRead bytes of new data from $startByte to $endByte");
            $this->readBufferedData($fp, $startByte, $endByte);
        }

        return new Response();
    }

    // Stream data to a client from given file pointer, starting at one position and ending at another
    // Flushes data in chunks of maximum 1KB to work around PHP's buffering
    // Assumes data existence at both start and end position on given file pointer, do data checks elsewhere!
    public function readBufferedData($fp, $startPosition, $endPosition)
    {
        // Set up logging
        $transcodeReadDataLog = $this->get('monolog.logger.transcode_send_data');

        // Make sure we don't cause any PHP warnings by trying to read from a boolean
        if (!is_resource($fp)) {
            $transcodeReadDataLog->error("readBufferedData(): Input parameter fp is not a resource, it is type: " . gettype($fp));
            return false;
        }

        // Seek to specified number of bytes from the beginning of the file
        fseek($fp, $startPosition);
        $transcodeReadDataLog->info("readBufferedData(): Seeking to start position: $startPosition");

        // Read data to the client in chunks no longer than 8KB until end position is reached.
        $bufferMax = 8192;
        while (1) {
            $currentPosition = ftell($fp);
            $transcodeReadDataLog->debug("readBufferedData(): Current position: $currentPosition");

            // Handle likely but unwanted file pointer values
            if ($currentPosition === false) {
                $transcodeReadDataLog->error("readBufferedData(): ftell returned false so something went wrong");
                return false;
            }
            if (feof($fp)) {
                $transcodeReadDataLog->error("readBufferedData(): Reached end of file before reaching endPosition");
                return false;
            }

            // End nicely if we reached the exact end byte as planned
            if ($currentPosition > $endPosition) {
                $transcodeReadDataLog->info("readBufferedData(): Position in file > endPosition so we have finished reading a chunk");
                return true;
            }

            // Calculate number of bytes to read if less than bufferMax
            if ($currentPosition + $bufferMax > $endPosition) {
                $readThisManyBytes = $endPosition - $currentPosition + 1;
            } else {
                $readThisManyBytes = $bufferMax;
            }

            $transcodeReadDataLog->debug("readBufferedData(): freading $readThisManyBytes bytes of data from position $currentPosition");
            echo fread($fp, $readThisManyBytes);
            flush();
        }

        return false;
    }

    // Get a rough estimate second to tell the transcode to start from, to simulate a byte range request
    public function getTranscodeStartSecond($startByte, $filesize, $duration)
    {
        // So we've been asked to start somewhere other than the start.
        // Get a rough estimate second to tell the transcode to start from by a dirty guesswork calculation
        $startSecond = round($duration / $filesize * $startByte);

        // If douchbag client has requested the last... 4... bytes, or some other ridiculously-close-to-EOF range, ignore them and go for the last 10 seconds
        if (($duration - $startSecond) < 10) {
            $startSecond = round($duration - 10);
        }

        return $startSecond;
    }

    // Check size of specified file to see if it has more than specified number of bytes
    public function dataExists($filepath, $bytes)
    {
        // Set up logging
        $transcodeLog = $this->get('monolog.logger.transcode');
        clearstatcache(); // Clear filesize cache
        $outputSize = filesize($filepath);
        if ($outputSize > $bytes) {
            $transcodeLog->debug("dataExists(): Checking output file for $bytes bytes, it does indeed have: $outputSize");
            return true;
        }
        $transcodeLog->debug("dataExists(): Checking output file for $bytes bytes, it only has: $outputSize");
        return false;
    }

    // Kill UNIX process with given PID if running on system
    // Returns true if process was killed or false if PID was not running
    public function killTranscodeProcess($pid)
    {
        $killProcess = new Process("kill -9 $pid");
        $killProcess->run();
        $killOutput = $killProcess->getOutput();
        if (strpos($killOutput, 'no such process') !== false) {
            return false;
        } else {
            return true;
        }
    }

    // Check if UNIX process with given PID and command name is running on system
    // Returns true or false 
    public function checkProcess($processName, $pid)
    {
        // Set up logging
        $transcodeLog = $this->get('monolog.logger.transcode');
        // Launch pidof to look for process
        $pidofProcess = new Process("pidof $processName");
        $pidofProcess->run();
        $pidofOutput = $pidofProcess->getOutput();

        $check = strpos($pidofOutput, "$pid");
        $transcodeLog->debug("checkProcess(): strpos returned: " . var_export($check, true) . " on the output from pidof: " . trim($pidofOutput));

        if ($check === false) {
            $transcodeLog->debug("checkProcess(): Process '$processName' with PID $pid was not found");
            return false;
        }
        $transcodeLog->debug("checkProcess(): Process '$processName' with PID $pid is still running");
        return true;
    }

    // Get basic data from client, find existing Client entity in database or create new one
    // In future this should check more than just IP address; cookies and possibly browser fingerprinting should be used to track clients
    public function getClient($fingerprintArray)
    {
        $em = $this->getDoctrine()->getManager();
        $client = $em->getRepository('ABTranscodingExperimentsBundle:Client')->findOneByIp($fingerprintArray['ip']);
        if (empty($client)) {
            $client = new Client();
            $client->setIp($fingerprintArray['ip']);
            $em->persist($client);
            $em->flush();
        }

        return $client;
    }

    // Choose transcoding parameters based on input file and client bitrate capability, create TranscodeProcess entity in database and start process
    public function createTranscodeProcess($clientID, $clientBitrate, $inputFilepath, $containerFormat, $startSecond)
    {
        // Set up logging
        $transcodeLog = $this->get('monolog.logger.transcode');

        $em = $this->getDoctrine()->getManager();
        $transcodeDirectory = $this->container->getParameter('kernel.cache_dir') . '/ABTranscodingExperimentsBundle/';

        $fs = new Filesystem();
        if ($fs->exists($transcodeDirectory) === false) {
            try {
                $fs->mkdir($transcodeDirectory);
            } catch (IOException $e) {
                echo "An error occurred while attempting to create temporary directory $transcodeDirectory";
            }
        }

        // Get path to statically built FFmpeg binary in resources directory. This should be re-built with build.sh for target platform.
        $ffmpegPath = $this->get('kernel')->getRootDir() .
            "/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffmpeg";
        $ffprobePath = $this->get('kernel')->getRootDir() .
            "/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffprobe";

        $ffprobe = FFMpeg\FFProbe::create(array(
            'ffmpeg.binaries' => $ffmpegPath,
            'ffprobe.binaries' => $ffprobePath
        ));
        // Extract input file duration in seconds, using FFprobe
        $duration = round($ffprobe->format($inputFilepath)->get('duration'), 2);
        // Get input bitrate in KB/s so we don't do anything stupid like transcode to a higher bitrate
        $inputBitrate = round($ffprobe->format($inputFilepath)->get('bit_rate') / 8192, 2);
        // First stream should be the video one, find out if it is h264 or not
        $inputCodec = $ffprobe->streams($inputFilepath)->videos()->first()->get('codec_name');

        $transcodeLog->info("createTranscodeProcess(): Input file codec: $inputCodec, bitrate: $inputBitrate KB/s");

        // If the input file already has a bitrate which is lower than our client's bandwidth, encode to match that
        $targetBitrate = $inputBitrate * 2 < $clientBitrate ? $inputBitrate * 2 : $clientBitrate;
        $transcodeLog->info("createTranscodeProcess(): Using bitrate: $targetBitrate KB/s for output file");

        // Container format; should be webm, flv or mp4 depending on client.
        switch ($containerFormat) {
            case 'webm':
                /* Example transcode string (200KB/s):
                INPUTFILEPATH="Sintel.2010.1080p.mkv"; OUTPUTFILEPATH="Sintel.2010.1080p.webm";
                alias customffmpeg=/home/andrew/Other/transcoding-experiments/src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffmpeg;
                customffmpeg -i $INPUTFILEPATH -c:v libvpx -quality good -b:v 200K -qmin 10 -qmax 42 -maxrate 200K -bufsize 400K -threads 14 -c:a libvorbis -qscale:a 3 -f webm -y $OUTPUTFILEPATH
                */
                
                // WebM is basically Matroska but using VP8 as the codec which is now royalty free thanks to google
                $format = 'webm';
                // Build video codec options
                $Vcodec = "-c:v libvpx -quality realtime -cpu-used 5 -b:v {$targetBitrate}K -qmin 10 -qmax 42 -maxrate {$targetBitrate}K -bufsize " . $targetBitrate * 2 . "K"; // around 50 fps, superb quality. "-quality realtime -cpu-used 3" does the same but only 36 fps.

                // Fairly low bitrate but decent enough quality for most purposes audio; using fdk_aac
                $Acodec = "-c:a libvorbis -qscale:a 3";

                // Putting h.264 / aac in a matroska container and pretending it is webm works for Chrome, but not Mozilla sadly
                //$format = 'matroska';
                //$Vcodec = "-c:v libx264 -b:v {$targetBitrate}K ";
                //$Acodec = "-c:a libfdk_aac -vbr 3 -ac 2";
                break;
            case 'flv':
                /* Example transcode string (200KB/s):
                INPUTFILEPATH="Sintel.2010.1080p.mkv"; OUTPUTFILEPATH="Sintel.2010.1080p.flv";
                customffmpeg -i $INPUTFILEPATH -c:v libx264 -b:v 200K -threads 14 -c:a libfdk_aac -vbr 3 -ac 2 -f flv -y $OUTPUTFILEPATH
                */
                
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
                /*if($targetBitrate > 200) {
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
                }*/
                // Build video codec options
                //$Vcodec = "-c:v libx264 -preset $preset -tune $tune -crf $crf -profile:v $profile -s $resolution";

                $Vcodec = "-c:v libx264 -b:v {$targetBitrate}K ";

                if ($inputCodec == "h264" && ($inputBitrate < $targetBitrate)) {
                    $transcodeLog->info("createTranscodeProcess(): Input file is h264 and has a lower video bitrate than client bandwidth, using c:v copy");
                    $Vcodec = "-c:v copy ";
                }

                // Fairly low bitrate but decent enough quality for most purposes audio; using fdk_aac
                $Acodec = "-c:a libfdk_aac -vbr 3 -ac 2";
                break;
            case 'mp4':
            default:
                /* Example transcode string (200KB/s):
                INPUTFILEPATH="Sintel.2010.1080p.mkv"; OUTPUTFILEPATH="Sintel.2010.1080p.mp4";
                customffmpeg -i $INPUTFILEPATH -c:v libx264 -b:v 200K -profile:v high -threads 14 -c:a libfdk_aac -vbr 3 -ac 2 -f mp4 -movflags frag_keyframe+empty_moov -y $OUTPUTFILEPATH
                */
                
                $format = 'mp4 -movflags frag_keyframe+empty_moov';

                // x264 Options
                // crf			# Quality factor. Lower is better quality, filesize increases exponentially. Sane range is 18-30
                // preset		# One of: ultrafast,superfast, veryfast, faster, fast, medium, slow, slower, veryslow or placebo
                // profile		# One of: baseline, main, high, high10, high422 or high444 
                // tune			# One of: film animation grain stillimage psnr ssim fastdecode zerolatency 
                // No need to vary these yet; may need to lower profile for certain mobile devices.
                //$profile = 'baseline';
                $profile = 'high';
                $tune = 'zerolatency';

                // Choose optimal encoding settings for various bitrate amounts; these best fits were calculated for animated movies.
                /*if($targetBitrate > 200) {
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
                }*/
                // Build video codec options
                //$Vcodec = "-c:v libx264 -preset $preset -tune $tune -crf $crf -profile:v $profile -s $resolution";
                //$Vcodec = "-c:v libx264 -b:v {$targetBitrate}K ";
                $Vcodec = "-c:v libx264 -b:v {$targetBitrate}K -profile:v $profile ";

                // if( $inputCodec == "h264" && ($inputBitrate < $targetBitrate) ) {
                // $transcodeLog->info("createTranscodeProcess(): Input file is h264 and has a lower video bitrate than client bandwidth, using c:v copy" );
                // $Vcodec = "-c:v copy ";
                // }

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
        $transcodeLog->info("createTranscodeProcess(): TranscodeProcess entity created with ID: $transcodeProcessID");

        $outputFilename = date('Y-m-d_H.i.s') . '_' . $transcodeProcessID . '.' . $containerFormat;
        $outputFilepath = $transcodeDirectory . $outputFilename;
        $commandOutputFile = $outputFilepath . '.log';

        $transcodeProcess->setOutputFilename($outputFilename);
        $transcodeProcess->setOutputFilepath($outputFilepath);

        // For now, simply add all subtitles from input file to output. This only works with the MKV container.
        //$subtitles = "-c:s copy";
        $subtitles = "";

        // Seek to a certain point in the file
        $startSecondString = " -ss $startSecond ";

        // Limit output length for testing
        //$durationLimit = "-t 60";
        $durationLimit = "";

        // Use 8 threads as almost all servers have at least this many, and the benefits with more anyway.
        $fullCommand = "$ffmpegPath $startSecondString -i \"$inputFilepath\" $Vcodec -threads 14 $Acodec $subtitles -f $format $durationLimit -y \"$outputFilepath\" > \"$commandOutputFile\" 2>&1";
        $transcodeProcess->setFullCommand($fullCommand);

        // Actually start the process
        $process = new Process($fullCommand);
        $process->start();
        // Since the PID returned is actually the php fork which disappears, the FFmpeg PID we want is just one greater
        $transcodeProcessPID = $process->getPid() + 1;
        $transcodeProcess->setProcessPID($transcodeProcessPID);
        // Write final TranscodeProcess entity to the database
        $em->flush();

        $transcodeLog->info("createTranscodeProcess(): TranscodeProcess with ID: $transcodeProcessID and PID: $transcodeProcessPID started");
        return $transcodeProcess;
    }

    // Get metadata from input file and return it in multiple formats in JSON 
    public function getMetadataAction($inputFilepath)
    {
        $transcodeLog = $this->get('monolog.logger.transcode');

        // Start looking at / parsing request variables and headers
        $inputFilepath = rawurldecode($inputFilepath);

        $transcodeLog->info("getMetadataAction(): Loading file: $inputFilepath");

        // Build absolute path to input video file, from Symfony application root directory, hard-coded video folder and path requested by client
        $rootDirectory = $this->get('kernel')->getRootDir() . '/../';
        $videoDirectory = 'web/bundles/abtranscodingexperiments/videos/';
        $absoluteInputFilepath = $rootDirectory . $videoDirectory . $inputFilepath;

        // Get path to statically built FFmpeg binary in resources directory. This should be re-built with build.sh for target platform.
        $ffprobe = FFMpeg\FFProbe::create(array(
            'ffmpeg.binaries' => $this->get('kernel')->getRootDir() .
                "/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffmpeg",
            'ffprobe.binaries' => $this->get('kernel')->getRootDir() .
                "/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/public/bin/ffmpeg-static/target/bin/ffprobe"
        ));
        // Extract input file duration in seconds, using FFprobe
        $duration = round($ffprobe->format($absoluteInputFilepath)->get('duration'), 2);

        $transcodeLog->info("getMetadataAction(): Extracted duration: $duration seconds");

        $response = new JsonResponse();
        $response->setData(array(
            'durationSeconds' => $duration,
            'durationMinutes' => gmdate("i:s", $duration)
        ));
        return $response;
    }

    // Send the browser a 1MB (or larger if needed) file to test the connection speed
    public function servePayloadAction($lengthKB, $startTime)
    {
        $response = new Response();
        $filename = $this->get('kernel')->getRootDir() . "/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/payload/5MB.bin";
        $response->headers->set('Cache-Control', 'private');
        $response->headers->set('Content-type', 'application/octet-stream');
        $response->headers->set('Content-length', $lengthKB * 1000);

        $d = $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'payload' . $lengthKB * 1000 . '.bin');
        $response->headers->set('Content-Disposition', $d);

        $response->sendHeaders();
        return $response->setContent(file_get_contents($filename, false, null, -1, $lengthKB * 1000));
    }

}

?>