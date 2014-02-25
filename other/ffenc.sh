#!/bin/bash 
# ----------------------------------------------------------------------------
# This script is a helper to transcode a video to H264+AAC with subtitles to a 
# Matroska (.mkv) container that is suitable for live streaming to a mobile 
# device. 
#
# Platform option
THREADS=10

# ----------------------------------------------------------------------------
# Video options 
# ----------------------------------------------------------------------------
#RES=1920x1080
RES=${3}

# Quality factor. Lower is better quality, filesize increases exponentially. Sane range is 18-30
#CRF=30
CRF=${4}

# One of: ultrafast,superfast, veryfast, faster, fast, medium, slow, slower, 
# veryslow or placebo 
#PRESET=fast 
PRESET=${5}

# One of: baseline, main, high, high10, high422 or high444 
PROFILE=high

# One of: film animation grain stillimage psnr ssim fastdecode zerolatency 
TUNE=zerolatency

# ----------------------------------------------------------------------------
# Audio options 
# ----------------------------------------------------------------------------
AUDIO="-c:a libfdk_aac -vbr 3 -ar 48000 -ac 2"

SUBTITLES="-c:s copy"

# ----------------------------------------------------------------------------
# Read input video parameters 
# ----------------------------------------------------------------------------
VIDEO="-c:v libx264 -preset ${PRESET} -tune ${TUNE} -crf ${CRF} -profile:v ${PROFILE} -s ${RES}"


echo ffmpeg -threads ${THREADS} -i "${1}" $VIDEO $AUDIO $SUBTITLES \
    -y "${2}" >> ffenc-command-log.txt

exec /usr/bin/ffmpeg -threads ${THREADS} -i "${1}" $VIDEO $AUDIO $SUBTITLES \
    -y "${2}" | tee ffenc-log.txt
