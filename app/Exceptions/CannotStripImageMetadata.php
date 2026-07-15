<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A raster upload could not be re-encoded, so its EXIF/GPS block could not be
 * removed.
 *
 * The platform tells reporters that image metadata is stripped on upload
 * (`upload_metadata_warning`). When that promise cannot be kept for a given
 * file, storing it anyway would hand an administrator the reporter's GPS
 * coordinates while the UI claimed otherwise — so the upload is refused and the
 * reporter is asked to re-save the image instead.
 *
 * This signals *reporter input* we cannot process (a corrupt or exotic file),
 * and is mapped to a field-level validation error. A missing GD extension is a
 * deployment fault, not reporter input, and deliberately raises a plain
 * RuntimeException instead.
 */
class CannotStripImageMetadata extends RuntimeException {}
