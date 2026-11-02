<?php

namespace App\Domain\Reports\Events;

/**
 * Rapport non transmis passé « hors délai réglementaire » au lendemain de J+15 : alerte du CNCGA
 * (RG-RPT-07). Aucune sanction automatique (CR-04).
 */
final class ReportLegallyLate extends ReportDeadlineEvent {}
