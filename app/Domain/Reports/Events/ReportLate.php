<?php

namespace App\Domain\Reports\Events;

/**
 * Rapport non transmis passé « en retard » au lendemain de la date limite J : alerte au CGA (RG-RPT-07).
 */
final class ReportLate extends ReportDeadlineEvent {}
