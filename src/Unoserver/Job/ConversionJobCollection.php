<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Job;

use Tabula17\Satelles\Utilis\Collection\TypedCollection;

class ConversionJobCollection extends TypedCollection
{

    protected static function getType(): string
    {
        return ConversionJob::class;
    }
}