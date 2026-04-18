<?php

namespace Tabula17\Satelles\Odf\Adiutor\Server;

enum AdiutorActionsEnum
{
    case Convert;
    case Submit;
    case Status;
    case Cancel;
    case Wait;
    case GetFile;

    public function path(): string
    {
        return match ($this) {
            self::Convert => 'convert',
            self::Submit => 'submit',
            self::Status => 'status',
            self::Cancel => 'cancel',
            self::Wait => 'wait',
            self::GetFile => 'getfile',
        };
    }
    public function method(): string
    {
        return match ($this) {
            self::Convert, self::Submit, self::Status, self::Cancel, self::Wait => 'POST',
            self::GetFile => 'GET',
        };
    }
    public function returnFile(): bool
    {
        return match ($this) {
            self::GetFile, self::Wait => true,
            default => false,
        };
    }
}
