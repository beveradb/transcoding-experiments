<?php

namespace AB\Bundle\TranscodingExperimentsBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * TranscodeProcess
 *
 * @ORM\Table()
 * @ORM\Entity(repositoryClass="AB\Bundle\TranscodingExperimentsBundle\Entity\TranscodeProcessRepository")
 */
class TranscodeProcess
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @var integer
     *
     * @ORM\Column(name="clientID", type="integer")
     */
    private $clientID;

    /**
     * @var string
     *
     * @ORM\Column(name="status", type="string", length=255)
     */
    private $status;

    /**
     * @var integer
     *
     * @ORM\Column(name="targetBitrate", type="integer")
     */
    private $targetBitrate;

    /**
     * @var string
     *
     * @ORM\Column(name="inputFilepath", type="text")
     */
    private $inputFilepath;

    /**
     * @var string
     *
     * @ORM\Column(name="duration", type="text")
     */
    private $duration;

    /**
     * @var string
     *
     * @ORM\Column(name="outputFilename", type="string", length=255, nullable=true)
     */
    private $outputFilename;

    /**
     * @var string
     *
     * @ORM\Column(name="outputFilepath", type="text", nullable=true)
     */
    private $outputFilepath;

    /**
     * @var string
     *
     * @ORM\Column(name="fullCommand", type="text", nullable=true)
     */
    private $fullCommand;

    /**
     * @var string
     *
     * @ORM\Column(name="processPID", type="string", length=255, nullable=true)
     */
    private $processPID;

    /**
     * Get id
     *
     * @return integer 
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set clientID
     *
     * @param integer $clientID
     * @return TranscodeProcess
     */
    public function setClientID($clientID)
    {
        $this->clientID = $clientID;

        return $this;
    }

    /**
     * Get clientID
     *
     * @return integer 
     */
    public function getClientID()
    {
        return $this->clientID;
    }

    /**
     * Set status
     *
     * @param string $status
     * @return TranscodeProcess
     */
    public function setStatus($status)
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Get status
     *
     * @return string 
     */
    public function getStatus()
    {
        return $this->status;
    }
    /**
     * Set targetBitrate
     *
     * @param integer $targetBitrate
     * @return TranscodeProcess
     */
    public function setTargetBitrate($targetBitrate)
    {
        $this->targetBitrate = $targetBitrate;

        return $this;
    }

    /**
     * Get targetBitrate
     *
     * @return integer 
     */
    public function getTargetBitrate()
    {
        return $this->targetBitrate;
    }

    /**
     * Set duration
     *
     * @param string $duration
     * @return TranscodeProcess
     */
    public function setDuration($duration)
    {
        $this->duration = $duration;

        return $this;
    }

    /**
     * Get duration
     *
     * @return string 
     */
    public function getDuration()
    {
        return $this->duration;
    }

    /**
     * Set inputFilepath
     *
     * @param string $inputFilepath
     * @return TranscodeProcess
     */
    public function setInputFilepath($inputFilepath)
    {
        $this->inputFilepath = $inputFilepath;

        return $this;
    }

    /**
     * Get inputFilepath
     *
     * @return string 
     */
    public function getInputFilepath()
    {
        return $this->inputFilepath;
    }

    /**
     * Set outputFilename
     *
     * @param string $outputFilename
     * @return TranscodeProcess
     */
    public function setOutputFilename($outputFilename)
    {
        $this->outputFilename = $outputFilename;

        return $this;
    }

    /**
     * Get outputFilename
     *
     * @return string 
     */
    public function getOutputFilename()
    {
        return $this->outputFilename;
    }

    /**
     * Set outputFilepath
     *
     * @param string $outputFilepath
     * @return TranscodeProcess
     */
    public function setOutputFilepath($outputFilepath)
    {
        $this->outputFilepath = $outputFilepath;

        return $this;
    }

    /**
     * Get outputFilepath
     *
     * @return string 
     */
    public function getOutputFilepath()
    {
        return $this->outputFilepath;
    }

    /**
     * Set fullCommand
     *
     * @param string $fullCommand
     * @return TranscodeProcess
     */
    public function setFullCommand($fullCommand)
    {
        $this->fullCommand = $fullCommand;

        return $this;
    }

    /**
     * Get fullCommand
     *
     * @return string 
     */
    public function getFullCommand()
    {
        return $this->fullCommand;
    }

    /**
     * Set processPID
     *
     * @param string $processPID
     * @return TranscodeProcess
     */
    public function setProcessPID($processPID)
    {
        $this->processPID = $processPID;

        return $this;
    }

    /**
     * Get processPID
     *
     * @return string 
     */
    public function getProcessPID()
    {
        return $this->processPID;
    }
}
