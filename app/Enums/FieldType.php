<?php

namespace App\Enums;

enum FieldType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case File = 'file';
    case Files = 'files';
    case Audio = 'audio';
    case Select = 'select';
    case Checkbox = 'checkbox';
    case Email = 'email';
    case Date = 'date';
    case Number = 'number';
    case Url = 'url';

    public function isFileUpload(): bool
    {
        // Audio is stored through the same upload pipeline as files, so it
        // counts as a file upload for body composition and storage.
        return $this === self::File || $this === self::Files || $this === self::Audio;
    }

    /**
     * Oral-reporting field (EU Directive Art. 9(2) / HinSchG §16): a single
     * audio recording or upload, validated against the audio allowlist rather
     * than the general file allowlist.
     */
    public function isAudio(): bool
    {
        return $this === self::Audio;
    }
}
