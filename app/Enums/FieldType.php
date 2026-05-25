<?php

namespace App\Enums;

enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case File = 'file';
    case Files = 'files';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Email = 'email';
    case Date = 'date';
    case Number = 'number';
    case Url = 'url';

    public function isFileUpload(): bool
    {
        return $this === self::File || $this === self::Files;
    }
}
