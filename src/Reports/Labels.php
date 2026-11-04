<?php

declare(strict_types=1);

namespace Waypost\Reports;

use Waypost\Enums\ChainFlag;
use Waypost\Enums\IncidentCategory;
use Waypost\Enums\ReportFrequency;
use Waypost\Enums\ScanMethod;
use Waypost\Enums\ShiftClosedBy;
use Waypost\Enums\VerifyStatus;

/** The Hungarian texts of the reports (the app's words, design src/i18n/hu.json, where it has them). */
final class Labels
{
    /**
     * Every scan flag, the app's (chain/payload-scan.json) and the server's (ChainFlag), most serious first: a point
     * with several flags shows the first one's style.
     */
    public const array FLAGS = [
        'mock_location' => 'hamis helyadat',
        'manual' => 'nem igazolt',
        'unknown_tag' => 'ismeretlen címke',
        'too_fast' => 'időgyanús: túl gyors',
        'time_suspect' => 'időgyanús',
        'far_from_checkpoint' => 'távol a ponttól',
        'out_of_order' => 'rossz sorrend',
        'out_of_round' => 'körön kívül',
        'late_sync' => 'később érkezett',
    ];

    /** The flags counted as "időgyanús" in the totals. */
    public const array SUSPICIOUS_FLAGS = [ChainFlag::TimeSuspect->value, ChainFlag::TooFast->value];

    private function __construct()
    {
    }

    public static function flag(string $flag): string
    {
        return self::FLAGS[$flag] ?? $flag;
    }

    public static function category(IncidentCategory $category): string
    {
        return match ($category) {
            IncidentCategory::Vandalism => 'Rongálás',
            IncidentCategory::OpenDoor => 'Nyitott ajtó / ablak',
            IncidentCategory::Water => 'Csőtörés / víz',
            IncidentCategory::Intruder => 'Idegen személy',
            IncidentCategory::DamagedTag => 'Sérült címke',
            IncidentCategory::Other => 'Egyéb',
        };
    }

    public static function method(ScanMethod $method): string
    {
        return match ($method) {
            ScanMethod::Nfc => 'NFC',
            ScanMethod::Qr => 'QR',
            ScanMethod::Manual => 'Kézi',
        };
    }

    public static function outcome(RoundOutcome $outcome): string
    {
        return match ($outcome) {
            RoundOutcome::Completed => 'Teljesítve',
            RoundOutcome::Incomplete => 'Hiányos',
            RoundOutcome::Missed => 'Elmaradt',
            RoundOutcome::Pending => 'Nincs lezárva',
            RoundOutcome::Upcoming => 'Hátravan',
        };
    }

    public static function closedBy(?ShiftClosedBy $closedBy): string
    {
        return match ($closedBy) {
            ShiftClosedBy::Guard => 'az őr zárta',
            ShiftClosedBy::Handover => 'átadással zárult',
            ShiftClosedBy::Auto => 'automatikusan zárult',
            null => 'nem zárult le',
        };
    }

    public static function verifyStatus(VerifyStatus $status): string
    {
        return match ($status) {
            VerifyStatus::Ok => 'rendben',
            VerifyStatus::Gap => 'hiányzó előzmény',
            VerifyStatus::Fork => 'elágazás',
            VerifyStatus::BadHmac => 'hibás aláírás',
        };
    }

    public static function incompleteReason(IncompleteReason $reason): string
    {
        return match ($reason) {
            IncompleteReason::NotSynced => 'a telefon az időszak vége óta nem szinkronizált',
            IncompleteReason::PendingEntries => 'a telefon néhány bejegyzése még feldolgozásra vár',
            IncompleteReason::PendingRounds => 'egy kör lezárása még nem érkezett meg',
            IncompleteReason::MissingPhotos => 'néhány fotó még nem töltődött fel',
        };
    }

    public static function report(ReportFrequency $type): string
    {
        return match ($type) {
            ReportFrequency::Shift => 'Műszakjelentés',
            ReportFrequency::Daily => 'Napi jelentés',
            ReportFrequency::Weekly => 'Heti jelentés',
            ReportFrequency::Monthly => 'Havi jelentés',
        };
    }
}
