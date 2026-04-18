<?php

namespace Tabula17\Satelles\Odf\Adiutor\Client;

use Swoole\Coroutine\Client;
use Tabula17\Satelles\Odf\Adiutor\Server\AdiutorActionsEnum;
use Tabula17\Satelles\Odf\Adiutor\Unoserver\Job\ConversionJob;
use Tabula17\Satelles\Utilis\Config\TCPServerConfig;

class AdiutorClientTcp extends Client
{


    public function __construct(protected TCPServerConfig $serverCfg, int $sockType = SOCK_STREAM)
    {
        parent::__construct($sockType);
    }

    public function convertFile(string $filePath, string $format = 'pdf'): void
    {

        $job = new ConversionJob(
            filePath: '',
            fileContent: ConversionJob::getContentFile($filePath),
            outputFormat: $format
        );
        if (!$this->connected) {
            $this->connect($this->serverCfg->host, $this->serverCfg->port);
        }
        $request = json_encode(array_merge($job->toArray(), ['action' => AdiutorActionsEnum::Convert->path()]));
        if ($this->send($request)) {
            echo $this->recv();
        } else {
            echo $this->errCode;
        }
    }
}