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
    case Info = 'info';

    /**
     * A display-only field: operator-authored formatted text (markdown + brand
     * colour shortcodes, exactly like a topic summary) rendered inline on the
     * form. It has no input control, so it is never validated and never
     * contributes an answer to the report body.
     */
    public function isDisplayOnly(): bool
    {
        return $this === self::Info;
    }

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
