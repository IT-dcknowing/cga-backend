# Revue sécurité du lot 9 — périmètre backend

Revue statique du backend, le 5 novembre 2026, selon 02-TRD §6.7 et OWASP Top 10:2025. Ce document ne vaut pas rapport de scan dynamique ni test d'intrusion.

| Risque | Contrôles backend observés | État |
|---|---|---|
| A01 — Contrôle d'accès défaillant | Policies, rattachement d'espace `tenant`, scope `BelongsToOrganization`, test `TenantIsolationTest` et journal d'accès technique prévu par RG-SEC-04. | Revue statique ; matrice rôle/route et scan dynamique à compléter. |
| A02 — Mauvaise configuration | Réponses d'erreur JSON centralisées ; CSP, HSTS et en-têtes associés configurés au serveur web dans `infra/`. | Configuration observée ; vérifier les en-têtes sur le déploiement HTTPS. |
| A03 — Défaillances de chaîne d'approvisionnement | Dépendances déclarées dans `composer.json` et `composer.lock`. | `composer audit` non exécuté : Composer CLI est absent de cet environnement. |
| A04 — Défaillances cryptographiques | Casts chiffrés Laravel pour les justificatifs d'identité, identifiants FNE, secrets de messagerie et observations de radiation ; TLS attendu au frontal. | Contrôles applicatifs observés ; vérifier la gestion des clés en recette. |
| A05 — Injection | Règles PHPStan et tests de garde-fous sur les données non validées et SQL brut ; requêtes paramétrées dans le code examiné. | Revue statique ; scan dynamique à compléter. |
| A06 — Conception non sécurisée | Écritures métier par Actions transactionnelles ; contraintes de cloisonnement et règles métier testées. | Revue statique ; test d'intrusion léger à compléter. |
| A07 — Défaillances d'authentification | Sessions Sanctum, CSRF, expiration d'inactivité, vérifications et limites de tentatives configurées. | Revue statique ; parcours d'authentification à rejouer en recette. |
| A08 — Intégrité logicielle ou des données | Validation serveur, Actions transactionnelles, contraintes de base et contrôles d'architecture ; sources externes prioritaires protégées par les règles métier existantes. | Revue statique ; scan dynamique à compléter. |
| A09 — Journalisation et alertes défaillantes | Requêtes et commandes journalisées ; exceptions envoyées à Sentry si DSN configuré ; journaux fichier simples et quotidiens au format JSON ; retard de file remonté par `/api/health`. | Contrôles applicatifs observés ; vérifier réception des alertes Sentry en recette. |
| A10 — Mauvaise gestion des conditions exceptionnelles | `BaseApiController::handle()`, mapper JSON commun et journalisation des erreurs de contrôles de santé. | Revue statique ; chemins d'erreur couverts par les tests existants. |

## Actions nécessaires pour clôturer le 9.3

- Exécuter `composer audit` et vérifier les politiques des routes avec la matrice complète des rôles.
- Rejouer le test de cloisonnement étendu.
- Exécuter le scan OWASP ZAP complet et le test d'intrusion léger sur la recette, puis corriger toute anomalie haute ou moyenne.
- Vérifier les en-têtes, les alertes Sentry et les limites de débit sur le déploiement HTTPS.

Le scanner OWASP ZAP et l'environnement de recette ne sont pas disponibles dans cette session ; aucune conclusion « sans anomalie haute ou moyenne » n'est donc déclarée.
