<?php

declare(strict_types=1);

namespace Waypost\Enums;

/** How a checkpoint is identified: NFC tag (plain or NTAG 424 DNA) or QR code. */
enum TagType: string
{
    case Nfc = 'nfc';
    case NfcDna = 'nfc_dna';
    case Qr = 'qr';
}
