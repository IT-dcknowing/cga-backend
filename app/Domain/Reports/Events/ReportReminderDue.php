<?php

namespace App\Domain\Reports\Events;

/**
 * Relance du rapport non transmis due aujourd'hui au gérant et à l'expert-comptable (RG-RPT-06) : offset
 * en jours par rapport à la date limite J (-15, -5, +5 par défaut).
 */
final class ReportReminderDue extends ReportDeadlineEvent {}
