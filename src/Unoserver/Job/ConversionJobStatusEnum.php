<?php

namespace Tabula17\Satelles\Odf\Adiutor\Unoserver\Job;

enum ConversionJobStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Retrying = 'retrying';
    case Cancelled = 'cancelled';
}
