<?php

namespace App\Domain\Reports\Events;

/**
 * Rapport trimestriel transmis au SP-CGA et enregistré par le CGA (F-RPT-04) : le CNCGA en est averti
 * (PRD M13 ; il ne voit que l'état et la synthèse agrégée, RG-RPT-04).
 */
final class ReportTransmitted extends ReportDeadlineEvent {}
