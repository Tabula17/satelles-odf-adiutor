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
            self::GetFile => 'getFile',
        };
    }

    /**
     * When implemented in HTTP Server determines the method to be used based on the current instance.
     *
     * @return string The HTTP method as a string ('POST' or 'GET').
     */
    public function method(): string
    {
        return match ($this) {
            self::Convert, self::Submit, self::Status, self::Cancel, self::Wait => 'POST',
            self::GetFile => 'GET',
        };
    }

    /**
     * Checks if the current instance is capable of returning a file.
     *
     * @return bool True if the instance can return a file, otherwise false.
     */
    public function canReturnFile(): bool
    {
        return match ($this) {
            self::GetFile, self::Wait => true,
            default => false,
        };
    }

    /**
     * List of all actions
     * @return array<string>
     */
    public static function list(): array
    {
        return array_map(static fn(self $action) => $action->path(), self::cases());
    }
    public static function fromString(string $action): self
    {
        return match ($action) {
            'convert' => self::Convert,
            'submit' => self::Submit,
            'status' => self::Status,
            'cancel' => self::Cancel,
            'wait' => self::Wait,
            'getFile' => self::GetFile,
            default => throw new \InvalidArgumentException("Invalid action: $action"),
        };
    }
}
