<?php

declare(strict_types=1);

namespace Waypost\Reports;

/** Why the data of a period may not be complete yet (spec: Jelentések / Tartalom: „nem teljes adat”). */
enum IncompleteReason: string
{
    /** A phone of the site has not synced since the period's data ended. */
    case NotSynced = 'not_synced';
    /** Chain entries wait: after a gap, or for configuration not on the server yet (projected_at null). */
    case PendingEntries = 'pending_entries';
    /** A round is still open on the server after its deadline: its close has not arrived. */
    case PendingRounds = 'pending_rounds';
    /** A photo of a scan or an incident has not been uploaded. */
    case MissingPhotos = 'missing_photos';
}
