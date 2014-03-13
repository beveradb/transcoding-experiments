<?php

namespace AB\Bundle\TranscodingExperimentsBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Process\Process;
use Zend\Json\Expr;

class DefaultController extends Controller
{
    public function indexAction()
    {
        return $this->render('ABTranscodingExperimentsBundle:Default:index.html.twig', array());
    }
	
	// Send the browser a 1MB (or larger if needed) file to test the connection speed
    public function servePayloadAction($lengthKB, $startTime)
    {
		$response = new Response();
		$filename = $this->get('kernel')->getRootDir()."/../src/AB/Bundle/TranscodingExperimentsBundle/Resources/payload/5MB.bin";
		$response->headers->set('Cache-Control', 'private');
		$response->headers->set('Content-type', 'application/octet-stream');
		$response->headers->set('Content-disposition', 'attachment; filename="payload-'. $lengthKB*1000 .'.bin"');
		$response->headers->set('Content-length', $lengthKB*1000);
		$response->sendHeaders();
		return $response->setContent(file_get_contents($filename, false, null, -1, $lengthKB*1000));
    }
}
