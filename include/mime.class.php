<?php

class MIME
{
    protected $finfo;

    public function __construct($mime_cache='/usr/share/misc/magic')
    {
        $this->finfo = new finfo(FILEINFO_MIME_TYPE, $mime_cache);
    }

    protected function bufGuessBinary(&$buf)
    {
        for ($i = 0; $i < 32 && $i < strlen($buf); $i++)
        {
            $c = ord($buf{$i});
            if ($c < 0x20 && $c != 0x09 /* \t */ && $c != 0x0A /* \n */)
                return TRUE;
        }
        return FALSE;
    }

    public function bufferGetType($buf, $filename=NULL)
    {
        $r = $this->finfo->buffer($buf);
        if ($r)
            return $r;
        return $this->bufGuessBinary($buf) ? 'application/octet-stream' : 'text/plain';
    }
}

?>
