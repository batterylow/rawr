<?php

namespace Beryllium\Rawr;

class Rawr {

    protected $exiv;
    protected $exiftool;
    private $rawExtensions = ['ari','arw','bay','crw','cr2','cap','dcs','dcr','dng','drf','eip','erf','fff','iiq','k25','kdc','mdc','mef','mos','mrw','nef','nrw','obm','orf','pef','ptx','pxn','r3d','raf','raw','rwl','rw2','rwz','sr2','srf','srw', 'x3f'];

    const EXIF_RAW = 'raw';

    public function __construct($exiv = null, $exiftool = null) {
        $this->sandbox  = rtrim(sys_get_temp_dir(), '/');
        $this->exiv = !is_null($exiv) ? $exiv : trim(`which exiv2`);
        $this->exiftool = !is_null($exiv) ? $exiftool : trim(`which exiftool`);
    }

    public function isRawFile($filePath) {
        return in_array(strToLower(pathinfo($filePath, PATHINFO_EXTENSION)), $this->rawExtensions);
    }

    public function isReady() {
        return is_dir($this->sandbox)
        && is_writable($this->sandbox)
        && file_exists($this->exiv)
        && is_executable($this->exiv)
        && $this->exiftool ? file_exists($this->exiftool) && is_executable($this->exiftool) : true;
    }

    public function extractPreview($raw, $previewDir, $previewFileName = null, $previewNumber = null, $overwrite = false) {

        if (!$this->isReady()) {
            throw new \RuntimeException('Not ready to extract previews');
        }

        if (!file_exists($raw)) {
            throw new \InvalidArgumentException('File does not exist: ' . $raw);
        }

        if (!$this->isRawFile($raw)) {
            throw new \InvalidArgumentException('Not raw file: ' . $raw);
        }

        // determine the extension by checking the mimetype
        // but of course, we have to convert the index to a zero-based lookup
        $previews  = $this->listPreviews($raw);

        if (is_null($previewNumber)) {
            $previewNumber = count($previews);
        }

        $previewId = ( (int) $previewNumber ) - 1;
        if (empty($previews[$previewId]['type']) || $previewId < 0) {
            throw new \InvalidArgumentException('Preview ' . $previewId . ' does not exist');
        }
        $outputExtension = $this->getExtensionFromType($previews[$previewId]['type']);


        // build the full filename
        $rawFilename = pathinfo($raw, PATHINFO_FILENAME);
        if(!$previewFileName){
            $previewFileName = $rawFilename;
        }
        $previewName = $previewFileName. '.' . $outputExtension;
        $previewPath = $previewDir . DIRECTORY_SEPARATOR . $previewName;
        $outputFile = $this->sandbox . DIRECTORY_SEPARATOR . $rawFilename.'-preview'.$previewNumber. '.' . $outputExtension;

        // exiv2 doesn't seem to have a quick fail, only a "force" option
        // we don't want to overwrite files by mistake, so we exit early
        if (file_exists($outputFile) && !$overwrite) {
            return false;
        }

        $cmd = escapeshellarg($this->exiv)
            . ' -ep'
            . escapeshellarg($previewNumber)
            . ' -l '
            . escapeshellarg($this->sandbox)
            . ' ex '
            . escapeshellarg($raw)
            . ' 2>&1 > /dev/null';
        exec($cmd);

        if (!file_exists($outputFile)) {
            throw new \RuntimeException('Extraction failed!');
        }

        if (file_exists($previewPath) && !$overwrite) {
            unlink($outputFile);
            throw new \RuntimeException('Preview exist!');
        }

        // copy the preview to the target path and remove the original
        copy($outputFile, $previewPath);
        unlink($outputFile);

        return $previewPath;
    }

    public function listPreviews($raw) {

        if (!$this->isReady()) {
            throw new \RuntimeException('Not ready to list previews');
        }
        if (!file_exists($raw)) {
            throw new \InvalidArgumentException('File does not exist: ' . $raw);
        }

        $output = null;
        $cmd    = escapeshellarg($this->exiv)
            . ' -pp'
            . ' pr '
            . escapeshellarg($raw);
        exec($cmd, $output);

        return $this->normalizePreviews($output);
    }

    protected function normalizePreviews($previews) {

        $rawPreviews = array_map(function ($preview) {
            $regex   = '/Preview (?P<index>[0-9]+): (?P<type>image\/[a-z]+), (?P<width>[0-9]+)x(?P<height>[0-9]+) pixels, (?P<size>[0-9]+) bytes/';
            $matches = array();
            $result  = preg_match($regex, $preview, $matches);
            return $matches;
        }, $previews);

        $previews = array();
        foreach ($rawPreviews as $preview) {
            $preview = array(
                'index'  => (int)$preview['index'],
                'type'   => $preview['type'],
                'height' => (int)$preview['height'],
                'width'  => (int)$preview['width'],
                'size'   => (int)$preview['size'],
            );
            $previews[] = $preview;
        }

        return $previews;
    }

    public function listExifData($raw, $type = 'raw') {

        if (!$this->isReady()) {
            throw new \RuntimeException('Not ready to list previews');
        }
        if (!file_exists($raw)) {
            throw new \InvalidArgumentException('File does not exist: ' . $raw);
        }

        $output = null;
        $cmd    = escapeshellarg($this->exiv)
            . ' -Pk'
            . ($type === static::EXIF_RAW ? 'v' : 't')
            . ' pr '
            . escapeshellarg($raw)
            . ' 2> /dev/null';
        exec($cmd, $output);

        return $this->normalizeExifData($output);
    }

    protected function normalizeExifData($data) {

        return array_reduce(
            array_map(
                function ($datum) {
                    $output = explode(' ', $datum, 2);

                    return array($output[0] => isset($output[1]) ? trim($output[1]) : null);
                },
                $data
            ),
            function ($carry, $item) {
                $carry += $item;

                return $carry;
            },
            array()
        );
    }

    public function transferExifData($source, $destination) {

        if (!$this->exiftool || !file_exists($this->exiftool) || !is_executable($this->exiftool)) {
            return;
        }
        if (!$this->isReady()) {
            throw new \RuntimeException('Not ready to list previews');
        }
        if (!file_exists($source)) {
            throw new \InvalidArgumentException('Source File does not exist: ' . $source);
        }
        if (!file_exists($destination)) {
            throw new \InvalidArgumentException('Destination File does not exist: ' . $destination);
        }

        $output = null;
        $cmd    = escapeshellarg($this->exiftool)
            . ' -overwrite_original '
            . ' -tagsFromFile '
            . escapeshellarg($source)
            . ' '
            . escapeshellarg($destination);
        exec($cmd, $output);
    }

    public function getExtensionFromType($type) {

        switch (strtolower($type)) {
            default:
            case 'image/jpg':
            case 'image/jpeg':
                return 'jpg';
            case 'image/tiff':
            case 'image/tif':   // This isn't really a thing.
                return 'tif';
        }
    }
}
