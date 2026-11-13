-- Schema generated from database/migrations using Laravel PostgreSQL grammar.
-- Target: PostgreSQL 13.23. Run only on a fresh, empty database.
BEGIN;

-- 0001_01_01_000000_create_users_table.php
-- Extensions optionnelles (unaccent, pg_trgm) : non requises. Si le serveur les propose plus tard,
-- les activer avec « php artisan db:extensions » (App\Support\Database\Extensions).
create table "users" ("id" char(26) not null, "first_name" varchar(100) not null, "last_name" varchar(100) not null, "phone" varchar(20) not null, "phone_verified_at" timestamp(0) with time zone null, "email" varchar(255) null, "email_verified_at" timestamp(0) with time zone null, "password" varchar(255) null, "status" varchar(20) not null default 'invited', "last_login_at" timestamp(0) with time zone null, "last_space_membership_id" char(26) null, "disabled_at" timestamp(0) with time zone null, "inactivity_notice_sent_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
create index "users_status_index" on "users" ("status");
alter table "users" add primary key ("id");
alter table "users" add constraint "users_phone_unique" unique ("phone");
CREATE UNIQUE INDEX users_email_unique ON users (email) WHERE email IS NOT NULL;
ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('invited', 'active', 'disabled', 'deleted'));
create table "sessions" ("id" varchar(255) not null, "user_id" char(26) null, "ip_address" varchar(45) null, "user_agent" text null, "payload" text not null, "last_activity" integer not null);
alter table "sessions" add primary key ("id");
create index "sessions_user_id_index" on "sessions" ("user_id");
create index "sessions_last_activity_index" on "sessions" ("last_activity");

-- 0001_01_01_000001_create_cache_table.php
create table "cache" ("key" varchar(255) not null, "value" text not null, "expiration" bigint not null);
alter table "cache" add primary key ("key");
create index "cache_expiration_index" on "cache" ("expiration");
create table "cache_locks" ("key" varchar(255) not null, "owner" varchar(255) not null, "expiration" bigint not null);
alter table "cache_locks" add primary key ("key");
create index "cache_locks_expiration_index" on "cache_locks" ("expiration");

-- 0001_01_01_000002_create_jobs_table.php
create table "jobs" ("id" bigserial not null primary key, "queue" varchar(255) not null, "payload" text not null, "attempts" smallint not null, "reserved_at" integer null, "available_at" integer not null, "created_at" integer not null);
create index "jobs_queue_index" on "jobs" ("queue");
create table "job_batches" ("id" varchar(255) not null, "name" varchar(255) not null, "total_jobs" integer not null, "pending_jobs" integer not null, "failed_jobs" integer not null, "failed_job_ids" text not null, "options" text null, "cancelled_at" integer null, "created_at" integer not null, "finished_at" integer null);
alter table "job_batches" add primary key ("id");
create table "failed_jobs" ("id" bigserial not null primary key, "uuid" varchar(255) not null, "connection" varchar(255) not null, "queue" varchar(255) not null, "payload" text not null, "exception" text not null, "failed_at" timestamp(0) without time zone not null default CURRENT_TIMESTAMP);
create index "failed_jobs_connection_queue_failed_at_index" on "failed_jobs" ("connection", "queue", "failed_at");
alter table "failed_jobs" add constraint "failed_jobs_uuid_unique" unique ("uuid");

-- 2026_10_20_100001_create_organizations_table.php
create table "organizations" ("id" char(26) not null, "type" varchar(20) not null, "name" varchar(200) not null, "sigle" varchar(50) null, "numbering_abbreviation" varchar(6) null, "abbreviation_locked_at" timestamp(0) with time zone null, "legal_form" varchar(40) null, "share_capital" bigint null, "ncc" varchar(20) null, "rccm" varchar(50) null, "agreement_number" varchar(60) null, "agreement_date" date null, "agreement_expires_at" date null, "agreement_status" varchar(20) not null default 'pending', "agreement_withdrawn_at" date null, "regional_directorate_id" char(26) null, "tax_office_id" char(26) null, "said" varchar(100) null, "address" text null, "city" varchar(100) not null, "commune_id" char(26) null, "phone_1" varchar(20) not null, "phone_2" varchar(20) null, "email" varchar(255) null, "manager_name" varchar(150) null, "expert_name" varchar(150) null, "signatory_name" varchar(150) null, "signatory_title" varchar(100) null, "logo_file_id" char(26) null, "signature_file_id" char(26) null, "stamp_file_id" char(26) null, "history_text" text null, "previous_year_revenue" bigint null, "status" varchar(20) not null default 'active', "suspended_at" timestamp(0) with time zone null, "suspension_reason" text null, "subscription_status" varchar(20) not null default 'none', "subscription_started_at" date null, "trial_ends_at" date null, "subscription_ended_at" date null, "data_deletion_due_at" date null, "banking_enabled" boolean not null default '0', "profile_completed_at" timestamp(0) with time zone null, "data_status" varchar(20) not null default 'provisional', "validated_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null, "deleted_at" timestamp(0) with time zone null);
create index "organizations_type_status_index" on "organizations" ("type", "status");
create index "organizations_subscription_status_index" on "organizations" ("subscription_status");
alter table "organizations" add primary key ("id");
alter table "organizations" add constraint "organizations_numbering_abbreviation_unique" unique ("numbering_abbreviation");
create index "organizations_regional_directorate_id_index" on "organizations" ("regional_directorate_id");
create index "organizations_tax_office_id_index" on "organizations" ("tax_office_id");
create index "organizations_commune_id_index" on "organizations" ("commune_id");
CREATE UNIQUE INDEX organizations_single_cncga ON organizations (type) WHERE type = 'cncga';
CREATE UNIQUE INDEX organizations_single_platform ON organizations (type) WHERE type = 'platform';
CREATE UNIQUE INDEX organizations_agreement_number_unique ON organizations (agreement_number) WHERE agreement_number IS NOT NULL;
ALTER TABLE organizations ADD CONSTRAINT organizations_type_check CHECK (type IN ('platform', 'cncga', 'cga'));
ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('active', 'suspended', 'terminated'));
ALTER TABLE organizations ADD CONSTRAINT organizations_agreement_status_check CHECK (agreement_status IN ('pending', 'granted', 'withdrawn'));
ALTER TABLE organizations ADD CONSTRAINT organizations_subscription_status_check CHECK (subscription_status IN ('none', 'trial', 'active', 'terminated'));
ALTER TABLE organizations ADD CONSTRAINT organizations_data_status_check CHECK (data_status IN ('provisional', 'validated'));
ALTER TABLE organizations ADD CONSTRAINT organizations_numbering_abbreviation_format CHECK (numbering_abbreviation ~ '^[A-Z]{2,6}$');
ALTER TABLE organizations ADD CONSTRAINT organizations_share_capital_positive CHECK (share_capital >= 0);
ALTER TABLE organizations ADD CONSTRAINT organizations_previous_year_revenue_positive CHECK (previous_year_revenue >= 0);

-- 2026_10_20_100002_create_files_table.php
create table "files" ("id" char(26) not null, "organization_id" char(26) null, "disk" varchar(20) not null, "path" varchar(500) not null, "original_name" varchar(255) not null, "mime_type" varchar(100) not null, "size_bytes" bigint not null, "sha256" char(64) not null, "uploaded_by" char(26) null, "created_at" timestamp(0) with time zone null);
alter table "files" add constraint "files_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "files" add constraint "files_uploaded_by_foreign" foreign key ("uploaded_by") references "users" ("id") on delete set null;
alter table "files" add primary key ("id");
create index "files_organization_id_index" on "files" ("organization_id");
alter table "files" add constraint "files_path_unique" unique ("path");
create index "files_uploaded_by_index" on "files" ("uploaded_by");
ALTER TABLE files ADD CONSTRAINT files_size_bytes_positive CHECK (size_bytes >= 0);
alter table "organizations" add constraint "organizations_logo_file_id_foreign" foreign key ("logo_file_id") references "files" ("id") on delete set null;
alter table "organizations" add constraint "organizations_signature_file_id_foreign" foreign key ("signature_file_id") references "files" ("id") on delete set null;
alter table "organizations" add constraint "organizations_stamp_file_id_foreign" foreign key ("stamp_file_id") references "files" ("id") on delete set null;

-- 2026_10_20_100003_create_memberships_table.php
create table "memberships" ("id" char(26) not null, "user_id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) null, "role" varchar(40) not null, "status" varchar(20) not null default 'invited', "read_only_until" date null, "invited_by" char(26) null, "activated_at" timestamp(0) with time zone null, "disabled_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "memberships" add constraint "memberships_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade;
alter table "memberships" add constraint "memberships_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "memberships" add constraint "memberships_invited_by_foreign" foreign key ("invited_by") references "users" ("id") on delete set null;
create index "memberships_organization_id_role_index" on "memberships" ("organization_id", "role");
alter table "memberships" add primary key ("id");
create index "memberships_user_id_index" on "memberships" ("user_id");
create index "memberships_member_id_index" on "memberships" ("member_id");
create index "memberships_invited_by_index" on "memberships" ("invited_by");
CREATE UNIQUE INDEX memberships_unique_role ON memberships (user_id, organization_id, role, COALESCE(member_id, ''));
ALTER TABLE memberships ADD CONSTRAINT memberships_role_check CHECK (role IN ('platform_admin', 'cncga_president', 'cncga_manager', 'cncga_dues', 'cncga_training', 'cncga_viewer', 'cga_manager', 'cga_accountant_expert', 'cga_accountant', 'cga_legal', 'cga_tax_advisor', 'cga_billing_officer', 'cga_trainer', 'cga_secretary', 'member_owner', 'member_user'));
ALTER TABLE memberships ADD CONSTRAINT memberships_status_check CHECK (status IN ('invited', 'active', 'read_only', 'disabled'));
ALTER TABLE memberships ADD CONSTRAINT memberships_member_role_has_member CHECK ((left(role, 7) = 'member_') = (member_id IS NOT NULL));
alter table "users" add constraint "users_last_space_membership_id_foreign" foreign key ("last_space_membership_id") references "memberships" ("id") on delete set null;

-- 2026_10_20_100004_create_invitations_table.php
create table "invitations" ("id" char(26) not null, "membership_id" char(26) not null, "token_hash" char(64) not null, "channel" varchar(20) not null, "sent_at" timestamp(0) with time zone not null, "expires_at" timestamp(0) with time zone not null, "accepted_at" timestamp(0) with time zone null, "revoked_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "invitations" add constraint "invitations_membership_id_foreign" foreign key ("membership_id") references "memberships" ("id") on delete cascade;
alter table "invitations" add primary key ("id");
create index "invitations_membership_id_index" on "invitations" ("membership_id");
alter table "invitations" add constraint "invitations_token_hash_unique" unique ("token_hash");
ALTER TABLE invitations ADD CONSTRAINT invitations_channel_check CHECK (channel IN ('sms', 'email', 'sms_email'));

-- 2026_10_20_100005_create_otp_codes_table.php
create table "otp_codes" ("id" bigserial not null primary key, "user_id" char(26) not null, "purpose" varchar(30) not null, "target" varchar(120) not null, "code_hash" char(64) not null, "attempts" smallint not null default '0', "expires_at" timestamp(0) with time zone not null, "consumed_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "otp_codes" add constraint "otp_codes_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade;
create index "otp_codes_user_id_index" on "otp_codes" ("user_id");
ALTER TABLE otp_codes ADD CONSTRAINT otp_codes_purpose_check CHECK (purpose IN ('password_reset', 'phone_change'));
ALTER TABLE otp_codes ADD CONSTRAINT otp_codes_attempts_range CHECK (attempts BETWEEN 0 AND 3);

-- 2026_10_20_100006_create_settings_table.php
create table "settings" ("id" bigserial not null primary key, "organization_id" char(26) null, "key" varchar(100) not null, "value" jsonb not null, "updated_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "settings" add constraint "settings_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id") on delete cascade;
alter table "settings" add constraint "settings_updated_by_foreign" foreign key ("updated_by") references "users" ("id") on delete set null;
create index "settings_organization_id_index" on "settings" ("organization_id");
CREATE UNIQUE INDEX settings_organization_key_unique ON settings (COALESCE(organization_id, ''), key);

-- 2026_10_20_100007_create_sequences_table.php
create table "sequences" ("id" bigserial not null primary key, "organization_id" char(26) not null, "key" varchar(40) not null, "year" smallint null, "last_value" bigint not null default '0', "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "sequences" add constraint "sequences_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id") on delete cascade;
CREATE UNIQUE INDEX sequences_organization_key_year_unique ON sequences (organization_id, key, COALESCE(year, 0));
ALTER TABLE sequences ADD CONSTRAINT sequences_last_value_positive CHECK (last_value >= 0);

-- 2026_10_20_100008_create_audit_logs_table.php
CREATE TABLE audit_logs (
    id bigint GENERATED BY DEFAULT AS IDENTITY,
    organization_id char(26) NULL,
    user_id char(26) NULL,
    membership_id char(26) NULL,
    event varchar(60) NOT NULL,
    subject_type varchar(80) NULL,
    subject_id varchar(26) NULL,
    changes jsonb NULL,
    context jsonb NULL,
    request_id varchar(40) NULL,
    ip inet NULL,
    user_agent varchar(255) NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
CREATE INDEX audit_logs_organization_created_at ON audit_logs (organization_id, created_at);
CREATE INDEX audit_logs_subject ON audit_logs (subject_type, subject_id);
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'audit_logs_default' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE audit_logs_default PARTITION OF audit_logs DEFAULT;
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'audit_logs_2026_11' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE audit_logs_2026_11 PARTITION OF audit_logs FOR VALUES FROM ('2026-11-01 00:00:00+00:00') TO ('2026-12-01 00:00:00+00:00');
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'audit_logs_2026_12' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE audit_logs_2026_12 PARTITION OF audit_logs FOR VALUES FROM ('2026-12-01 00:00:00+00:00') TO ('2027-01-01 00:00:00+00:00');
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'audit_logs_2027_01' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE audit_logs_2027_01 PARTITION OF audit_logs FOR VALUES FROM ('2027-01-01 00:00:00+00:00') TO ('2027-02-01 00:00:00+00:00');
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'audit_logs_2027_02' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE audit_logs_2027_02 PARTITION OF audit_logs FOR VALUES FROM ('2027-02-01 00:00:00+00:00') TO ('2027-03-01 00:00:00+00:00');

-- 2026_10_20_100009_create_platform_access_logs_table.php
create table "platform_access_logs" ("id" bigserial not null primary key, "admin_user_id" char(26) not null, "organization_id" char(26) not null, "reason" text not null, "route" varchar(200) null, "started_at" timestamp(0) with time zone not null, "ended_at" timestamp(0) with time zone null);
alter table "platform_access_logs" add constraint "platform_access_logs_admin_user_id_foreign" foreign key ("admin_user_id") references "users" ("id");
alter table "platform_access_logs" add constraint "platform_access_logs_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
create index "platform_access_logs_organization_id_started_at_index" on "platform_access_logs" ("organization_id", "started_at");
create index "platform_access_logs_admin_user_id_index" on "platform_access_logs" ("admin_user_id");

-- 2026_10_20_100010_create_role_permissions_table.php
create table "role_permissions" ("id" bigserial not null primary key, "organization_id" char(26) null, "role" varchar(40) not null, "permission" varchar(80) not null, "granted" boolean not null, "updated_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "role_permissions" add constraint "role_permissions_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id") on delete cascade;
alter table "role_permissions" add constraint "role_permissions_updated_by_foreign" foreign key ("updated_by") references "users" ("id") on delete set null;
create index "role_permissions_organization_id_index" on "role_permissions" ("organization_id");
CREATE UNIQUE INDEX role_permissions_unique ON role_permissions (COALESCE(organization_id, ''), role, permission);
ALTER TABLE role_permissions ADD CONSTRAINT role_permissions_role_check CHECK (role IN ('cncga_president', 'cncga_manager', 'cncga_dues', 'cncga_training', 'cncga_viewer', 'cga_manager', 'cga_accountant_expert', 'cga_accountant', 'cga_legal', 'cga_tax_advisor', 'cga_billing_officer', 'cga_trainer', 'cga_secretary', 'member_owner', 'member_user'));

-- 2026_10_20_100011_create_user_permission_overrides_table.php
create table "user_permission_overrides" ("id" bigserial not null primary key, "organization_id" char(26) not null, "membership_id" char(26) not null, "permission" varchar(80) not null, "granted" boolean not null, "reason" varchar(255) null, "updated_by" char(26) not null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "user_permission_overrides" add constraint "user_permission_overrides_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id") on delete cascade;
alter table "user_permission_overrides" add constraint "user_permission_overrides_membership_id_foreign" foreign key ("membership_id") references "memberships" ("id") on delete cascade;
alter table "user_permission_overrides" add constraint "user_permission_overrides_updated_by_foreign" foreign key ("updated_by") references "users" ("id");
alter table "user_permission_overrides" add constraint "user_permission_overrides_membership_id_permission_unique" unique ("membership_id", "permission");
create index "user_permission_overrides_organization_id_index" on "user_permission_overrides" ("organization_id");

-- 2026_10_20_100012_create_holidays_table.php
create table "holidays" ("id" bigserial not null primary key, "date" date not null, "label" varchar(100) not null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "holidays" add constraint "holidays_date_unique" unique ("date");

-- 2026_10_20_100013_create_activity_logs_table.php
CREATE TABLE activity_logs (
    id bigint GENERATED BY DEFAULT AS IDENTITY,
    created_at timestamptz NOT NULL DEFAULT now(),
    request_id varchar(40) NULL,
    actor_type varchar(20) NOT NULL CHECK (actor_type IN ('user', 'system', 'public')),
    actor_label varchar(60) NULL,
    user_id char(26) NULL,
    membership_id char(26) NULL,
    role varchar(40) NULL,
    organization_id char(26) NULL,
    action varchar(100) NOT NULL,
    method varchar(10) NULL,
    route varchar(200) NULL,
    subject_type varchar(80) NULL,
    subject_id varchar(26) NULL,
    params jsonb NULL,
    result varchar(20) NOT NULL CHECK (result IN ('success', 'validation_failed', 'denied', 'not_found', 'business_error', 'throttled', 'error')),
    http_status smallint NULL,
    duration_ms integer NULL,
    error_code varchar(60) NULL,
    ip inet NULL,
    user_agent varchar(255) NULL,
    PRIMARY KEY (id, created_at)
) PARTITION BY RANGE (created_at);
CREATE INDEX activity_logs_organization_created_at ON activity_logs (organization_id, created_at);
CREATE INDEX activity_logs_user_created_at ON activity_logs (user_id, created_at);
CREATE INDEX activity_logs_subject ON activity_logs (subject_type, subject_id);
CREATE INDEX activity_logs_action_created_at ON activity_logs (action, created_at);
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'activity_logs_default' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE activity_logs_default PARTITION OF activity_logs DEFAULT;
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'activity_logs_2026_11' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE activity_logs_2026_11 PARTITION OF activity_logs FOR VALUES FROM ('2026-11-01 00:00:00+00:00') TO ('2026-12-01 00:00:00+00:00');
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'activity_logs_2026_12' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE activity_logs_2026_12 PARTITION OF activity_logs FOR VALUES FROM ('2026-12-01 00:00:00+00:00') TO ('2027-01-01 00:00:00+00:00');
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'activity_logs_2027_01' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE activity_logs_2027_01 PARTITION OF activity_logs FOR VALUES FROM ('2027-01-01 00:00:00+00:00') TO ('2027-02-01 00:00:00+00:00');
select exists (select 1 from pg_class c, pg_namespace n where n.nspname = current_schema() and c.relname = 'activity_logs_2027_02' and c.relkind in ('r', 'p') and n.oid = c.relnamespace);
CREATE TABLE activity_logs_2027_02 PARTITION OF activity_logs FOR VALUES FROM ('2027-02-01 00:00:00+00:00') TO ('2027-03-01 00:00:00+00:00');

-- 2026_10_20_100014_create_notification_preferences_table.php
create table "notification_preferences" ("user_id" char(26) not null, "channel" varchar(20) not null, "opted_out" boolean not null, "consented_at" timestamp(0) with time zone null);
alter table "notification_preferences" add constraint "notification_preferences_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete cascade;
alter table "notification_preferences" add primary key ("user_id", "channel");
ALTER TABLE notification_preferences ADD CONSTRAINT notification_preferences_channel_check CHECK (channel IN ('sms', 'whatsapp'));

-- 2026_10_21_100001_create_regional_directorates_table.php
create table "regional_directorates" ("id" char(26) not null, "code" varchar(20) not null, "name" varchar(150) not null, "geo_key" varchar(50) null, "active" boolean not null default '1', "data_status" varchar(20) not null default 'provisional', "validated_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "regional_directorates" add primary key ("id");
alter table "regional_directorates" add constraint "regional_directorates_code_unique" unique ("code");
ALTER TABLE regional_directorates ADD CONSTRAINT regional_directorates_data_status_check CHECK (data_status IN ('provisional', 'validated'));

-- 2026_10_21_100002_create_tax_offices_table.php
create table "tax_offices" ("id" char(26) not null, "regional_directorate_id" char(26) not null, "code" varchar(20) not null, "name" varchar(150) not null, "active" boolean not null default '1', "data_status" varchar(20) not null default 'provisional', "validated_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "tax_offices" add constraint "tax_offices_regional_directorate_id_foreign" foreign key ("regional_directorate_id") references "regional_directorates" ("id") on delete restrict;
alter table "tax_offices" add primary key ("id");
create index "tax_offices_regional_directorate_id_index" on "tax_offices" ("regional_directorate_id");
alter table "tax_offices" add constraint "tax_offices_code_unique" unique ("code");
ALTER TABLE tax_offices ADD CONSTRAINT tax_offices_data_status_check CHECK (data_status IN ('provisional', 'validated'));

-- 2026_10_21_100003_create_communes_table.php
create table "communes" ("id" char(26) not null, "name" varchar(120) not null, "regional_directorate_id" char(26) null, "data_status" varchar(20) not null default 'provisional', "validated_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "communes" add constraint "communes_regional_directorate_id_foreign" foreign key ("regional_directorate_id") references "regional_directorates" ("id") on delete restrict;
alter table "communes" add primary key ("id");
alter table "communes" add constraint "communes_name_unique" unique ("name");
create index "communes_regional_directorate_id_index" on "communes" ("regional_directorate_id");
ALTER TABLE communes ADD CONSTRAINT communes_data_status_check CHECK (data_status IN ('provisional', 'validated'));

-- 2026_10_21_100004_create_sectors_table.php
create table "sectors" ("id" char(26) not null, "code" varchar(10) not null, "name" varchar(120) not null, "status" varchar(20) not null default 'validated', "proposed_by_organization_id" char(26) null, "merged_into_id" char(26) null, "validated_by" char(26) null, "validated_at" timestamp(0) with time zone null, "data_status" varchar(20) not null default 'provisional', "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "sectors" add constraint "sectors_proposed_by_organization_id_foreign" foreign key ("proposed_by_organization_id") references "organizations" ("id") on delete set null;
alter table "sectors" add constraint "sectors_validated_by_foreign" foreign key ("validated_by") references "users" ("id") on delete set null;
create index "sectors_status_index" on "sectors" ("status");
alter table "sectors" add primary key ("id");
alter table "sectors" add constraint "sectors_code_unique" unique ("code");
create index "sectors_proposed_by_organization_id_index" on "sectors" ("proposed_by_organization_id");
alter table "sectors" add constraint "sectors_merged_into_id_foreign" foreign key ("merged_into_id") references "sectors" ("id") on delete restrict;
ALTER TABLE sectors ADD CONSTRAINT sectors_status_check CHECK (status IN ('validated', 'proposed', 'rejected', 'merged'));
ALTER TABLE sectors ADD CONSTRAINT sectors_data_status_check CHECK (data_status IN ('provisional', 'validated'));

-- 2026_10_21_100005_create_national_references_table.php
create table "national_references" ("id" char(26) not null, "year" smallint not null, "domestic_tax_revenue" bigint null, "nominal_gdp" bigint null, "tax_revenue_source" varchar(200) null, "gdp_source" varchar(200) null, "updated_by" char(26) null, "data_status" varchar(20) not null default 'provisional', "validated_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "national_references" add constraint "national_references_updated_by_foreign" foreign key ("updated_by") references "users" ("id") on delete set null;
alter table "national_references" add primary key ("id");
alter table "national_references" add constraint "national_references_year_unique" unique ("year");
ALTER TABLE national_references ADD CONSTRAINT national_references_data_status_check CHECK (data_status IN ('provisional', 'validated'));
ALTER TABLE national_references ADD CONSTRAINT national_references_amounts_check CHECK (domestic_tax_revenue >= 0 AND nominal_gdp >= 0);

-- 2026_10_21_100006_add_referential_foreign_keys_to_organizations_table.php
alter table "organizations" add constraint "organizations_regional_directorate_id_foreign" foreign key ("regional_directorate_id") references "regional_directorates" ("id") on delete restrict;
alter table "organizations" add constraint "organizations_tax_office_id_foreign" foreign key ("tax_office_id") references "tax_offices" ("id") on delete restrict;
alter table "organizations" add constraint "organizations_commune_id_foreign" foreign key ("commune_id") references "communes" ("id") on delete restrict;

-- 2026_10_21_100007_create_organization_status_events_table.php
create table "organization_status_events" ("id" bigserial not null primary key, "organization_id" char(26) not null, "event" varchar(30) not null, "reason" text not null, "actor_id" char(26) null, "occurred_at" timestamp(0) with time zone not null);
alter table "organization_status_events" add constraint "organization_status_events_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id") on delete cascade;
alter table "organization_status_events" add constraint "organization_status_events_actor_id_foreign" foreign key ("actor_id") references "users" ("id") on delete set null;
create index "organization_status_events_organization_id_index" on "organization_status_events" ("organization_id");
ALTER TABLE organization_status_events ADD CONSTRAINT organization_status_events_event_check CHECK (event IN ('suspended', 'reactivated', 'agreement_withdrawn', 'subscription_changed', 'terminated'));

-- 2026_10_21_100008_create_import_batches_table.php
create table "import_batches" ("id" char(26) not null, "organization_id" char(26) null, "type" varchar(20) not null, "file_id" char(26) not null, "status" varchar(20) not null default 'queued', "total_rows" integer not null default '0', "imported_rows" integer not null default '0', "draft_rows" integer not null default '0', "rejected_rows" integer not null default '0', "report_file_id" char(26) null, "created_by" char(26) null, "created_at" timestamp(0) with time zone null, "finished_at" timestamp(0) with time zone null);
alter table "import_batches" add constraint "import_batches_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "import_batches" add constraint "import_batches_file_id_foreign" foreign key ("file_id") references "files" ("id");
alter table "import_batches" add constraint "import_batches_report_file_id_foreign" foreign key ("report_file_id") references "files" ("id");
alter table "import_batches" add constraint "import_batches_created_by_foreign" foreign key ("created_by") references "users" ("id") on delete set null;
alter table "import_batches" add primary key ("id");
create index "import_batches_organization_id_index" on "import_batches" ("organization_id");
ALTER TABLE import_batches ADD CONSTRAINT import_batches_type_check CHECK (type IN ('cgas', 'members', 'revenues', 'tax_payments'));
ALTER TABLE import_batches ADD CONSTRAINT import_batches_status_check CHECK (status IN ('queued', 'processing', 'done', 'failed'));
ALTER TABLE import_batches ADD CONSTRAINT import_batches_counts_positive CHECK (total_rows >= 0 AND imported_rows >= 0 AND draft_rows >= 0 AND rejected_rows >= 0);
create table "import_rows" ("id" bigserial not null primary key, "batch_id" char(26) not null, "row_number" integer not null, "status" varchar(20) not null, "messages" jsonb not null default '[]', "created_entity_id" char(26) null);
alter table "import_rows" add constraint "import_rows_batch_id_foreign" foreign key ("batch_id") references "import_batches" ("id");
alter table "import_rows" add constraint "import_rows_batch_id_row_number_unique" unique ("batch_id", "row_number");
ALTER TABLE import_rows ADD CONSTRAINT import_rows_status_check CHECK (status IN ('imported', 'draft', 'rejected'));

-- 2026_10_22_100001_create_activity_codes_and_business_referrers_tables.php
create table "activity_codes" ("id" char(26) not null, "code" varchar(20) not null, "label" varchar(200) not null, "sector_id" char(26) null, "active" boolean not null default '1', "data_status" varchar(20) not null default 'provisional', "validated_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "activity_codes" add constraint "activity_codes_sector_id_foreign" foreign key ("sector_id") references "sectors" ("id");
alter table "activity_codes" add primary key ("id");
alter table "activity_codes" add constraint "activity_codes_code_unique" unique ("code");
create index "activity_codes_sector_id_index" on "activity_codes" ("sector_id");
ALTER TABLE activity_codes ADD CONSTRAINT activity_codes_data_status_check CHECK (data_status IN ('provisional', 'validated'));
create table "business_referrers" ("id" char(26) not null, "organization_id" char(26) not null, "label" varchar(40) not null, "name" varchar(150) not null, "phone" varchar(20) null, "email" varchar(255) null, "terms" text null, "active" boolean not null default '1', "created_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "business_referrers" add constraint "business_referrers_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "business_referrers" add constraint "business_referrers_created_by_foreign" foreign key ("created_by") references "users" ("id") on delete set null;
alter table "business_referrers" add constraint "business_referrers_organization_id_label_unique" unique ("organization_id", "label");
alter table "business_referrers" add primary key ("id");
create index "business_referrers_organization_id_index" on "business_referrers" ("organization_id");

-- 2026_10_22_100002_create_members_table.php
create table "members" ("id" char(26) not null, "organization_id" char(26) not null, "portfolio" varchar(10) not null default 'cga', "status" varchar(20) not null default 'draft', "membership_number" varchar(40) null, "membership_seq" integer null, "client_number" varchar(40) null, "legacy_number" varchar(60) null, "referrer_id" char(26) null, "cga_contract_signed_on" date null, "cga_contract_confirmed_by" char(26) null, "cga_contract_ended_on" date null, "cga_contract_file_id" char(26) null, "attestation_blocked" boolean not null default '0', "adhesion_date" date null, "validated_at" timestamp(0) with time zone null, "validated_by" char(26) null, "entity_type" varchar(20) not null, "company_name" varchar(200) not null, "sigle" varchar(50) null, "legal_form" varchar(30) not null, "share_capital" bigint null, "owner_last_name" varchar(150) null, "owner_first_names" varchar(150) null, "owner_nationality" varchar(80) null, "owner_id_document_type" varchar(30) null, "owner_id_document_number" text null, "ncc" varchar(12) not null, "rccm" varchar(50) null, "idu" varchar(50) null, "regime" varchar(5) not null, "regime_since" date not null, "tax_office_id" char(26) null, "activity_code_id" char(26) null, "activity_code_text" varchar(20) null, "sector_id" char(26) null, "main_activity" varchar(255) not null, "address" text null, "commune_id" char(26) null, "land_section" varchar(30) null, "land_parcel" varchar(30) null, "registration_date" date null, "phone" varchar(20) not null, "whatsapp" varchar(20) null, "email" varchar(255) null, "signatory_name" varchar(150) null, "signatory_title" varchar(100) null, "employees_count" integer null, "entry_fee_amount" bigint not null default '0', "fees_amount" bigint not null default '0', "fees_periodicity" varchar(15) not null default 'monthly', "pieces_status" varchar(20) not null default 'pending', "rsi_since" date null, "search_text" text not null generated always as (lower(company_name || ' ' || coalesce(sigle, '') || ' ' || ncc || ' ' || coalesce(membership_number, '') || ' ' || coalesce(client_number, '') || ' ' || coalesce(legacy_number, '') || ' ' || phone)) stored, "created_by" char(26) null, "updated_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null, "deleted_at" timestamp(0) with time zone null);
alter table "members" add constraint "members_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "members" add constraint "members_referrer_id_foreign" foreign key ("referrer_id") references "business_referrers" ("id");
alter table "members" add constraint "members_cga_contract_confirmed_by_foreign" foreign key ("cga_contract_confirmed_by") references "users" ("id") on delete set null;
alter table "members" add constraint "members_cga_contract_file_id_foreign" foreign key ("cga_contract_file_id") references "files" ("id");
alter table "members" add constraint "members_validated_by_foreign" foreign key ("validated_by") references "users" ("id") on delete set null;
alter table "members" add constraint "members_tax_office_id_foreign" foreign key ("tax_office_id") references "tax_offices" ("id");
alter table "members" add constraint "members_activity_code_id_foreign" foreign key ("activity_code_id") references "activity_codes" ("id");
alter table "members" add constraint "members_sector_id_foreign" foreign key ("sector_id") references "sectors" ("id");
alter table "members" add constraint "members_commune_id_foreign" foreign key ("commune_id") references "communes" ("id");
alter table "members" add constraint "members_created_by_foreign" foreign key ("created_by") references "users" ("id") on delete set null;
alter table "members" add constraint "members_updated_by_foreign" foreign key ("updated_by") references "users" ("id") on delete set null;
alter table "members" add constraint "members_organization_id_membership_number_unique" unique ("organization_id", "membership_number");
alter table "members" add constraint "members_organization_id_client_number_unique" unique ("organization_id", "client_number");
create index "members_organization_id_portfolio_status_index" on "members" ("organization_id", "portfolio", "status");
create index "members_organization_id_referrer_id_index" on "members" ("organization_id", "referrer_id");
create index "members_organization_id_legacy_number_index" on "members" ("organization_id", "legacy_number");
create index "members_organization_id_status_index" on "members" ("organization_id", "status");
create index "members_organization_id_regime_index" on "members" ("organization_id", "regime");
create index "members_organization_id_tax_office_id_index" on "members" ("organization_id", "tax_office_id");
create index "members_organization_id_adhesion_date_index" on "members" ("organization_id", "adhesion_date");
alter table "members" add primary key ("id");
ALTER TABLE members ADD CONSTRAINT members_portfolio_check CHECK (portfolio IN ('cga', 'cabinet'));
ALTER TABLE members ADD CONSTRAINT members_status_check CHECK (status IN ('draft', 'active', 'radiation_pending', 'radiated', 'closed'));
ALTER TABLE members ADD CONSTRAINT members_entity_type_check CHECK (entity_type IN ('company', 'individual'));
ALTER TABLE members ADD CONSTRAINT members_legal_form_check CHECK (legal_form IN ('SARL', 'SARLU', 'SAS', 'SASU', 'SA', 'SNC', 'GIE', 'COOP', 'EI', 'OTHER'));
ALTER TABLE members ADD CONSTRAINT members_regime_check CHECK (regime IN ('RME', 'TEE', 'RSI', 'RNI'));
ALTER TABLE members ADD CONSTRAINT members_fees_periodicity_check CHECK (fees_periodicity IN ('monthly', 'quarterly', 'yearly'));
ALTER TABLE members ADD CONSTRAINT members_pieces_status_check CHECK (pieces_status IN ('pending', 'complete'));
ALTER TABLE members ADD CONSTRAINT members_ncc_format CHECK (ncc ~ '^[0-9]{7}[A-Z]$');
ALTER TABLE members ADD CONSTRAINT members_employees_count_positive CHECK (employees_count IS NULL OR employees_count >= 0);
ALTER TABLE members ADD CONSTRAINT members_amounts_positive CHECK (entry_fee_amount >= 0 AND fees_amount >= 0);
ALTER TABLE members ADD CONSTRAINT members_referrer_cga_only CHECK (referrer_id IS NULL OR portfolio = 'cga');
ALTER TABLE members ADD CONSTRAINT members_cga_requires_contract CHECK (portfolio <> 'cga' OR status = 'draft' OR cga_contract_signed_on IS NOT NULL);
CREATE UNIQUE INDEX members_open_ncc_unique ON members (organization_id, ncc) WHERE status IN ('draft', 'active', 'radiation_pending') AND deleted_at IS NULL;
CREATE INDEX members_cga_ncc_index ON members (ncc) WHERE portfolio = 'cga' AND status IN ('active', 'radiation_pending');
alter table "memberships" add constraint "memberships_member_id_foreign" foreign key ("member_id") references "members" ("id");

-- 2026_10_22_100003_create_member_records_tables.php
create table "member_regime_history" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "regime" varchar(5) not null, "effective_date" date not null, "reason" text not null, "justification_file_id" char(26) null, "created_by" char(26) null, "created_at" timestamp(0) with time zone null);
alter table "member_regime_history" add constraint "member_regime_history_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "member_regime_history" add constraint "member_regime_history_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "member_regime_history" add constraint "member_regime_history_justification_file_id_foreign" foreign key ("justification_file_id") references "files" ("id");
alter table "member_regime_history" add constraint "member_regime_history_created_by_foreign" foreign key ("created_by") references "users" ("id") on delete set null;
create index "member_regime_history_member_id_effective_date_index" on "member_regime_history" ("member_id", "effective_date");
alter table "member_regime_history" add primary key ("id");
create index "member_regime_history_organization_id_index" on "member_regime_history" ("organization_id");
ALTER TABLE member_regime_history ADD CONSTRAINT member_regime_history_regime_check CHECK (regime IN ('RME', 'TEE', 'RSI', 'RNI'));
create table "member_revenues" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "year" smallint not null, "amount" bigint not null, "kind" varchar(20) not null, "source" varchar(20) not null, "synced_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "member_revenues" add constraint "member_revenues_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "member_revenues" add constraint "member_revenues_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "member_revenues" add constraint "member_revenues_member_id_year_kind_unique" unique ("member_id", "year", "kind");
alter table "member_revenues" add primary key ("id");
create index "member_revenues_organization_id_index" on "member_revenues" ("organization_id");
ALTER TABLE member_revenues ADD CONSTRAINT member_revenues_kind_check CHECK (kind IN ('forecast', 'actual'));
ALTER TABLE member_revenues ADD CONSTRAINT member_revenues_source_check CHECK (source IN ('entry', 'import', 'report', 'compta_flow'));
ALTER TABLE member_revenues ADD CONSTRAINT member_revenues_amount_positive CHECK (amount >= 0);
create table "member_pieces" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "piece_type" varchar(30) not null, "status" varchar(20) not null default 'expected', "file_id" char(26) null, "received_at" date null, "expires_at" date null, "last_reminder_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "member_pieces" add constraint "member_pieces_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "member_pieces" add constraint "member_pieces_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "member_pieces" add constraint "member_pieces_file_id_foreign" foreign key ("file_id") references "files" ("id");
alter table "member_pieces" add constraint "member_pieces_member_id_piece_type_unique" unique ("member_id", "piece_type");
alter table "member_pieces" add primary key ("id");
create index "member_pieces_organization_id_index" on "member_pieces" ("organization_id");
ALTER TABLE member_pieces ADD CONSTRAINT member_pieces_piece_type_check CHECK (piece_type IN ('rccm', 'dfe', 'id_document', 'statutes', 'location_plan', 'signed_cga_contract'));
ALTER TABLE member_pieces ADD CONSTRAINT member_pieces_status_check CHECK (status IN ('expected', 'received', 'expired', 'not_applicable'));
create table "radiations" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "initiator" varchar(20) not null, "reason" varchar(30) not null, "reason_other" varchar(200) null, "status" varchar(20) not null default 'requested', "effective_date" date not null, "observation" text not null, "proof_file_id" char(26) null, "sp_request_reference" varchar(100) null, "requested_by" char(26) not null, "requested_at" timestamp(0) with time zone not null, "validated_by" char(26) null, "validated_at" timestamp(0) with time zone null, "validation_reference" varchar(100) null, "outcome" varchar(20) null, "notification_document_id" char(26) null, "report_year" smallint null, "report_quarter" smallint null, "cancelled_at" timestamp(0) with time zone null, "cancelled_by" char(26) null, "cancel_reason" text null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "radiations" add constraint "radiations_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "radiations" add constraint "radiations_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "radiations" add constraint "radiations_proof_file_id_foreign" foreign key ("proof_file_id") references "files" ("id");
alter table "radiations" add constraint "radiations_requested_by_foreign" foreign key ("requested_by") references "users" ("id");
alter table "radiations" add constraint "radiations_validated_by_foreign" foreign key ("validated_by") references "users" ("id");
alter table "radiations" add constraint "radiations_cancelled_by_foreign" foreign key ("cancelled_by") references "users" ("id");
create index "radiations_organization_id_report_year_report_quarter_index" on "radiations" ("organization_id", "report_year", "report_quarter");
create index "radiations_organization_id_status_index" on "radiations" ("organization_id", "status");
alter table "radiations" add primary key ("id");
ALTER TABLE radiations ADD CONSTRAINT radiations_initiator_check CHECK (initiator IN ('cga', 'member', 'sp_cga'));
ALTER TABLE radiations ADD CONSTRAINT radiations_reason_check CHECK (reason IN ('zone_change', 'follow_up_impossible', 'serious_breach', 'reclassified_real_regime', 'cessation', 'business_sale', 'death', 'dissatisfaction', 'firm_change', 'mutual_termination', 'agreement_loss', 'other'));
ALTER TABLE radiations ADD CONSTRAINT radiations_status_check CHECK (status IN ('requested', 'validated', 'cancelled'));
ALTER TABLE radiations ADD CONSTRAINT radiations_outcome_check CHECK (outcome IN ('closed', 'moved_to_cabinet'));
ALTER TABLE radiations ADD CONSTRAINT radiations_report_quarter_range CHECK (report_quarter IS NULL OR report_quarter BETWEEN 1 AND 4);
CREATE UNIQUE INDEX radiations_member_current_unique ON radiations (member_id) WHERE cancelled_at IS NULL;
create table "portfolio_movements" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "direction" varchar(20) not null, "effective_date" date not null, "reason" text not null, "contract_signed_on" date null, "contract_file_id" char(26) null, "radiation_id" char(26) null, "previous_referrer_id" char(26) null, "created_by" char(26) not null, "created_at" timestamp(0) with time zone null);
alter table "portfolio_movements" add constraint "portfolio_movements_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "portfolio_movements" add constraint "portfolio_movements_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "portfolio_movements" add constraint "portfolio_movements_contract_file_id_foreign" foreign key ("contract_file_id") references "files" ("id");
alter table "portfolio_movements" add constraint "portfolio_movements_radiation_id_foreign" foreign key ("radiation_id") references "radiations" ("id");
alter table "portfolio_movements" add constraint "portfolio_movements_previous_referrer_id_foreign" foreign key ("previous_referrer_id") references "business_referrers" ("id");
alter table "portfolio_movements" add constraint "portfolio_movements_created_by_foreign" foreign key ("created_by") references "users" ("id");
create index "portfolio_movements_organization_id_effective_date_index" on "portfolio_movements" ("organization_id", "effective_date");
alter table "portfolio_movements" add primary key ("id");
create index "portfolio_movements_member_id_index" on "portfolio_movements" ("member_id");
ALTER TABLE portfolio_movements ADD CONSTRAINT portfolio_movements_direction_check CHECK (direction IN ('cabinet_to_cga', 'cga_to_cabinet'));
ALTER TABLE portfolio_movements ADD CONSTRAINT portfolio_movements_contract_to_cga CHECK (direction <> 'cabinet_to_cga' OR contract_signed_on IS NOT NULL);
ALTER TABLE portfolio_movements ADD CONSTRAINT portfolio_movements_radiation_to_cabinet CHECK (direction <> 'cga_to_cabinet' OR radiation_id IS NOT NULL);
create table "member_transfers" ("id" char(26) not null, "from_member_id" char(26) not null, "to_organization_id" char(26) not null, "to_member_id" char(26) null, "consent_file_id" char(26) not null, "requested_by" char(26) not null, "created_at" timestamp(0) with time zone null);
alter table "member_transfers" add constraint "member_transfers_from_member_id_foreign" foreign key ("from_member_id") references "members" ("id");
alter table "member_transfers" add constraint "member_transfers_to_organization_id_foreign" foreign key ("to_organization_id") references "organizations" ("id");
alter table "member_transfers" add constraint "member_transfers_to_member_id_foreign" foreign key ("to_member_id") references "members" ("id");
alter table "member_transfers" add constraint "member_transfers_consent_file_id_foreign" foreign key ("consent_file_id") references "files" ("id");
alter table "member_transfers" add constraint "member_transfers_requested_by_foreign" foreign key ("requested_by") references "users" ("id");
alter table "member_transfers" add primary key ("id");
create index "member_transfers_to_organization_id_index" on "member_transfers" ("to_organization_id");
create table "fee_schedules" ("id" char(26) not null, "organization_id" char(26) not null, "kind" varchar(20) not null, "regime" varchar(5) null, "amount" bigint not null, "periodicity" varchar(15) null, "valid_from" date not null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "fee_schedules" add constraint "fee_schedules_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "fee_schedules" add primary key ("id");
ALTER TABLE fee_schedules ADD CONSTRAINT fee_schedules_kind_check CHECK (kind IN ('entry_fee', 'fees'));
ALTER TABLE fee_schedules ADD CONSTRAINT fee_schedules_regime_check CHECK (regime IN ('RME', 'TEE'));
ALTER TABLE fee_schedules ADD CONSTRAINT fee_schedules_periodicity_check CHECK (periodicity IN ('monthly', 'quarterly', 'yearly'));
ALTER TABLE fee_schedules ADD CONSTRAINT fee_schedules_amount_positive CHECK (amount >= 0);
CREATE UNIQUE INDEX fee_schedules_unique ON fee_schedules (organization_id, kind, COALESCE(regime, ''), valid_from);
create table "audit_attachments" ("audit_log_id" bigint not null, "file_id" char(26) not null);
alter table "audit_attachments" add constraint "audit_attachments_file_id_foreign" foreign key ("file_id") references "files" ("id");
alter table "audit_attachments" add primary key ("audit_log_id", "file_id");

-- 2026_10_22_100004_one_pending_radiation_per_member.php
DROP INDEX radiations_member_current_unique;
CREATE UNIQUE INDEX radiations_member_pending_unique ON radiations (member_id) WHERE status = 'requested';

-- 2026_10_23_100001_create_documents_tables.php
create table "document_templates" ("id" char(26) not null, "type" varchar(40) not null, "version" varchar(20) not null, "file_id" char(26) not null, "variables" jsonb not null, "is_active" boolean not null default '0', "approved_by_cncga_at" timestamp(0) with time zone null, "notes" text null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "document_templates" add constraint "document_templates_file_id_foreign" foreign key ("file_id") references "files" ("id");
alter table "document_templates" add constraint "document_templates_type_version_unique" unique ("type", "version");
alter table "document_templates" add primary key ("id");
ALTER TABLE document_templates ADD CONSTRAINT document_templates_type_check CHECK (type IN ('membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx', 'quarterly_report_modern', 'quarterly_report_xlsx', 'report_transmission_slip', 'dues_receipt', 'dues_invoice', 'training_certificate', 'training_receipt', 'statistics_brochure', 'banking_file'));
CREATE UNIQUE INDEX document_templates_one_active ON document_templates (type) WHERE is_active;
create table "generated_documents" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) null, "type" varchar(40) not null, "title" varchar(200) not null, "number" varchar(60) null, "version" smallint not null default '1', "replaces_document_id" char(26) null, "template_id" char(26) null, "docx_file_id" char(26) null, "pdf_file_id" char(26) null, "xlsx_file_id" char(26) null, "verification_token" varchar(64) null, "sha256" char(64) null, "status" varchar(20) not null default 'generating', "issued_at" timestamp(0) with time zone null, "valid_until" date null, "fiscal_year" smallint null, "cancelled_at" timestamp(0) with time zone null, "cancelled_by" char(26) null, "cancel_reason" text null, "visible_to_member" boolean not null default '1', "source_type" varchar(40) null, "source_id" char(26) null, "generated_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "generated_documents" add constraint "generated_documents_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "generated_documents" add constraint "generated_documents_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "generated_documents" add constraint "generated_documents_template_id_foreign" foreign key ("template_id") references "document_templates" ("id");
alter table "generated_documents" add constraint "generated_documents_docx_file_id_foreign" foreign key ("docx_file_id") references "files" ("id");
alter table "generated_documents" add constraint "generated_documents_pdf_file_id_foreign" foreign key ("pdf_file_id") references "files" ("id");
alter table "generated_documents" add constraint "generated_documents_xlsx_file_id_foreign" foreign key ("xlsx_file_id") references "files" ("id");
alter table "generated_documents" add constraint "generated_documents_cancelled_by_foreign" foreign key ("cancelled_by") references "users" ("id");
alter table "generated_documents" add constraint "generated_documents_generated_by_foreign" foreign key ("generated_by") references "users" ("id");
alter table "generated_documents" add constraint "generated_documents_organization_id_type_number_unique" unique ("organization_id", "type", "number");
create index "generated_documents_member_id_type_index" on "generated_documents" ("member_id", "type");
create index "generated_documents_organization_id_type_issued_at_index" on "generated_documents" ("organization_id", "type", "issued_at");
alter table "generated_documents" add primary key ("id");
alter table "generated_documents" add constraint "generated_documents_verification_token_unique" unique ("verification_token");
alter table "generated_documents" add constraint "generated_documents_replaces_document_id_foreign" foreign key ("replaces_document_id") references "generated_documents" ("id");
ALTER TABLE generated_documents ADD CONSTRAINT generated_documents_type_check CHECK (type IN ('membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx', 'quarterly_report_modern', 'quarterly_report_xlsx', 'report_transmission_slip', 'dues_receipt', 'dues_invoice', 'training_certificate', 'training_receipt', 'statistics_brochure', 'banking_file', 'uploaded'));
ALTER TABLE generated_documents ADD CONSTRAINT generated_documents_status_check CHECK (status IN ('generating', 'valid', 'cancelled', 'failed'));
ALTER TABLE generated_documents ADD CONSTRAINT generated_documents_version_positive CHECK (version >= 1);
ALTER TABLE generated_documents ADD CONSTRAINT generated_documents_cancel_reason CHECK (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancel_reason IS NOT NULL));
CREATE UNIQUE INDEX generated_documents_one_certificate_per_year ON generated_documents (member_id, fiscal_year) WHERE type = 'membership_certificate' AND status = 'valid' AND version = 1;
create table "document_verifications" ("id" bigserial not null primary key, "document_id" char(26) not null, "ip_prefix" varchar(50) null, "user_agent" varchar(255) null, "verified_at" timestamp(0) with time zone not null);
alter table "document_verifications" add constraint "document_verifications_document_id_foreign" foreign key ("document_id") references "generated_documents" ("id");
create index "document_verifications_document_id_verified_at_index" on "document_verifications" ("document_id", "verified_at");

-- 2026_10_23_100002_create_payments_tables.php
create table "member_charges" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "kind" varchar(20) not null, "period_start" date null, "period_end" date null, "due_date" date not null, "amount" bigint not null, "amount_paid" bigint not null default '0', "status" varchar(20) not null default 'due', "overdue_since" date null, "training_registration_id" char(26) null, "invoice_id" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "member_charges" add constraint "member_charges_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "member_charges" add constraint "member_charges_member_id_foreign" foreign key ("member_id") references "members" ("id");
create index "member_charges_organization_id_status_due_date_index" on "member_charges" ("organization_id", "status", "due_date");
create index "member_charges_member_id_index" on "member_charges" ("member_id");
alter table "member_charges" add primary key ("id");
ALTER TABLE member_charges ADD CONSTRAINT member_charges_kind_check CHECK (kind IN ('entry_fee', 'fees', 'training'));
ALTER TABLE member_charges ADD CONSTRAINT member_charges_status_check CHECK (status IN ('due', 'partial', 'paid', 'overdue', 'cancelled'));
ALTER TABLE member_charges ADD CONSTRAINT member_charges_amounts CHECK (amount >= 0 AND amount_paid >= 0 AND amount_paid <= amount);
ALTER TABLE member_charges ADD CONSTRAINT member_charges_fees_period CHECK (kind <> 'fees' OR (period_start IS NOT NULL AND period_end IS NOT NULL AND period_start <= period_end));
CREATE UNIQUE INDEX member_charges_fees_period_unique ON member_charges (member_id, kind, period_start) WHERE kind = 'fees';
CREATE UNIQUE INDEX member_charges_one_entry_fee ON member_charges (member_id) WHERE kind = 'entry_fee' AND status <> 'cancelled';
create table "member_payments" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "amount" bigint not null, "paid_on" date not null, "method" varchar(20) not null, "reference" varchar(100) null, "proof_file_id" char(26) null, "source" varchar(20) not null, "status" varchar(20) not null, "rejection_reason" text null, "confirmed_by" char(26) null, "confirmed_at" timestamp(0) with time zone null, "receipt_number" varchar(40) null, "receipt_document_id" char(26) null, "recorded_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "member_payments" add constraint "member_payments_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "member_payments" add constraint "member_payments_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "member_payments" add constraint "member_payments_proof_file_id_foreign" foreign key ("proof_file_id") references "files" ("id");
alter table "member_payments" add constraint "member_payments_confirmed_by_foreign" foreign key ("confirmed_by") references "users" ("id");
alter table "member_payments" add constraint "member_payments_receipt_document_id_foreign" foreign key ("receipt_document_id") references "generated_documents" ("id");
alter table "member_payments" add constraint "member_payments_recorded_by_foreign" foreign key ("recorded_by") references "users" ("id");
alter table "member_payments" add constraint "member_payments_organization_id_receipt_number_unique" unique ("organization_id", "receipt_number");
create index "member_payments_organization_id_status_paid_on_index" on "member_payments" ("organization_id", "status", "paid_on");
create index "member_payments_member_id_index" on "member_payments" ("member_id");
alter table "member_payments" add primary key ("id");
ALTER TABLE member_payments ADD CONSTRAINT member_payments_method_check CHECK (method IN ('cash', 'cheque', 'transfer', 'mobile_money', 'other'));
ALTER TABLE member_payments ADD CONSTRAINT member_payments_source_check CHECK (source IN ('cga_entry', 'member_declaration', 'import'));
ALTER TABLE member_payments ADD CONSTRAINT member_payments_status_check CHECK (status IN ('pending_confirmation', 'confirmed', 'rejected'));
ALTER TABLE member_payments ADD CONSTRAINT member_payments_amount_positive CHECK (amount > 0);
ALTER TABLE member_payments ADD CONSTRAINT member_payments_declared_proof CHECK (source <> 'member_declaration' OR proof_file_id IS NOT NULL);
ALTER TABLE member_payments ADD CONSTRAINT member_payments_rejection_reason CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL);
create table "member_payment_allocations" ("id" bigserial not null primary key, "payment_id" char(26) not null, "charge_id" char(26) not null, "amount" bigint not null);
alter table "member_payment_allocations" add constraint "member_payment_allocations_payment_id_foreign" foreign key ("payment_id") references "member_payments" ("id");
alter table "member_payment_allocations" add constraint "member_payment_allocations_charge_id_foreign" foreign key ("charge_id") references "member_charges" ("id");
alter table "member_payment_allocations" add constraint "member_payment_allocations_payment_id_charge_id_unique" unique ("payment_id", "charge_id");
create index "member_payment_allocations_charge_id_index" on "member_payment_allocations" ("charge_id");
ALTER TABLE member_payment_allocations ADD CONSTRAINT member_payment_allocations_amount_positive CHECK (amount > 0);

-- 2026_10_24_100001_document_versions_and_categories.php
alter table "generated_documents" drop constraint "generated_documents_organization_id_type_number_unique";
alter table "generated_documents" add constraint "generated_documents_organization_id_type_number_version_unique" unique ("organization_id", "type", "number", "version");
alter table "generated_documents" add column "category" varchar(40) null;
ALTER TABLE generated_documents ADD CONSTRAINT generated_documents_category_check CHECK (category IN ('financial_statements', 'certificate', 'other'));
ALTER TABLE generated_documents ADD CONSTRAINT generated_documents_category_uploaded CHECK (category IS NULL OR type = 'uploaded');

-- 2026_10_25_100001_create_attestation_blocks_table.php
create table "attestation_blocks" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "trigger" varchar(20) not null, "reason" text not null, "blocked_by" char(26) not null, "blocked_at" timestamp(0) with time zone not null, "unblocked_by" char(26) null, "unblocked_at" timestamp(0) with time zone null, "unblock_reason" text null);
alter table "attestation_blocks" add constraint "attestation_blocks_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "attestation_blocks" add constraint "attestation_blocks_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "attestation_blocks" add constraint "attestation_blocks_blocked_by_foreign" foreign key ("blocked_by") references "users" ("id");
alter table "attestation_blocks" add constraint "attestation_blocks_unblocked_by_foreign" foreign key ("unblocked_by") references "users" ("id");
create index "attestation_blocks_organization_id_blocked_at_index" on "attestation_blocks" ("organization_id", "blocked_at");
create index "attestation_blocks_member_id_blocked_at_index" on "attestation_blocks" ("member_id", "blocked_at");
alter table "attestation_blocks" add primary key ("id");
ALTER TABLE attestation_blocks ADD CONSTRAINT attestation_blocks_trigger_check CHECK (trigger IN ('fees_overdue', 'rsi_overdue', 'manual'));
ALTER TABLE attestation_blocks ADD CONSTRAINT attestation_blocks_unblock_complete CHECK ((unblocked_at IS NULL AND unblocked_by IS NULL AND unblock_reason IS NULL) OR (unblocked_at IS NOT NULL AND unblocked_by IS NOT NULL AND unblock_reason IS NOT NULL));
CREATE UNIQUE INDEX attestation_blocks_one_active ON attestation_blocks (member_id) WHERE unblocked_at IS NULL;

-- 2026_10_26_100001_create_invoices_tables.php
alter table "member_charges" add column "amount_excl_tax" bigint null;
alter table "member_charges" add column "tax_rate" decimal(5, 2) not null default '0';
alter table "member_charges" add column "tax_amount" bigint not null default '0';
UPDATE member_charges SET amount_excl_tax = amount;
alter table "member_charges" alter column "amount_excl_tax" type bigint, alter column "amount_excl_tax" set not null, alter column "amount_excl_tax" drop default, alter column "amount_excl_tax" drop identity if exists;
comment on column "member_charges"."amount_excl_tax" is NULL;
ALTER TABLE member_charges ADD CONSTRAINT member_charges_tax CHECK (amount_excl_tax >= 0 AND tax_amount >= 0 AND tax_rate >= 0 AND tax_rate <= 100 AND amount = amount_excl_tax + tax_amount);
create table "invoicing_providers" ("id" char(26) not null, "organization_id" char(26) not null, "mode" varchar(20) not null default 'manual', "base_url" varchar(255) null, "credentials" text null, "fne_number_pattern" varchar(120) null, "active" boolean not null default '1', "last_test_at" timestamp(0) with time zone null, "last_test_ok" boolean null, "updated_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "invoicing_providers" add constraint "invoicing_providers_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "invoicing_providers" add constraint "invoicing_providers_updated_by_foreign" foreign key ("updated_by") references "users" ("id") on delete set null;
alter table "invoicing_providers" add primary key ("id");
alter table "invoicing_providers" add constraint "invoicing_providers_organization_id_unique" unique ("organization_id");
ALTER TABLE invoicing_providers ADD CONSTRAINT invoicing_providers_mode_check CHECK (mode IN ('manual', 'dgi_api', 'sell_flow'));
create table "invoices" ("id" char(26) not null, "organization_id" char(26) not null, "type" varchar(20) not null default 'invoice', "original_invoice_id" char(26) null, "member_id" char(26) null, "billed_organization_id" char(26) null, "number" varchar(40) not null, "issue_date" date not null, "lines" jsonb not null, "amount_excl_tax" bigint not null, "tax_amount" bigint not null, "amount_incl_tax" bigint not null, "status" varchar(30) not null default 'draft', "certification_mode" varchar(20) not null, "fne_number" varchar(80) null, "fne_qr_payload" text null, "fne_stamp" text null, "certified_at" timestamp(0) with time zone null, "rejection_reason" text null, "certified_file_id" char(26) null, "document_id" char(26) null, "cancel_reason" text null, "created_by" char(26) not null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "invoices" add constraint "invoices_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "invoices" add constraint "invoices_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "invoices" add constraint "invoices_billed_organization_id_foreign" foreign key ("billed_organization_id") references "organizations" ("id");
alter table "invoices" add constraint "invoices_certified_file_id_foreign" foreign key ("certified_file_id") references "files" ("id");
alter table "invoices" add constraint "invoices_document_id_foreign" foreign key ("document_id") references "generated_documents" ("id");
alter table "invoices" add constraint "invoices_created_by_foreign" foreign key ("created_by") references "users" ("id");
alter table "invoices" add constraint "invoices_organization_id_number_unique" unique ("organization_id", "number");
create index "invoices_organization_id_status_index" on "invoices" ("organization_id", "status");
create index "invoices_member_id_index" on "invoices" ("member_id");
alter table "invoices" add primary key ("id");
alter table "invoices" add constraint "invoices_original_invoice_id_foreign" foreign key ("original_invoice_id") references "invoices" ("id");
ALTER TABLE invoices ADD CONSTRAINT invoices_type_check CHECK (type IN ('invoice', 'credit_note'));
ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'pending_certification', 'certified', 'rejected', 'cancelled'));
ALTER TABLE invoices ADD CONSTRAINT invoices_certification_mode_check CHECK (certification_mode IN ('manual', 'dgi_api', 'sell_flow'));
ALTER TABLE invoices ADD CONSTRAINT invoices_one_client CHECK ((member_id IS NULL) <> (billed_organization_id IS NULL));
ALTER TABLE invoices ADD CONSTRAINT invoices_credit_note_original CHECK (type <> 'credit_note' OR original_invoice_id IS NOT NULL);
ALTER TABLE invoices ADD CONSTRAINT invoices_certified_number CHECK (status <> 'certified' OR (fne_number IS NOT NULL AND certified_at IS NOT NULL));
ALTER TABLE invoices ADD CONSTRAINT invoices_amounts CHECK (amount_excl_tax >= 0 AND tax_amount >= 0 AND amount_incl_tax = amount_excl_tax + tax_amount);
CREATE UNIQUE INDEX invoices_one_credit_note ON invoices (original_invoice_id) WHERE type = 'credit_note' AND status <> 'cancelled';
create table "fne_certifications" ("id" bigserial not null primary key, "organization_id" char(26) not null, "invoice_id" char(26) not null, "attempt" smallint not null, "requested_at" timestamp(0) with time zone not null, "responded_at" timestamp(0) with time zone null, "status" varchar(20) not null, "provider_reference" varchar(100) null, "message" text null, "next_retry_at" timestamp(0) with time zone null);
alter table "fne_certifications" add constraint "fne_certifications_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "fne_certifications" add constraint "fne_certifications_invoice_id_foreign" foreign key ("invoice_id") references "invoices" ("id");
alter table "fne_certifications" add constraint "fne_certifications_invoice_id_attempt_unique" unique ("invoice_id", "attempt");
create index "fne_certifications_next_retry_at_index" on "fne_certifications" ("next_retry_at");
ALTER TABLE fne_certifications ADD CONSTRAINT fne_certifications_status_check CHECK (status IN ('sent', 'accepted', 'rejected', 'error'));
alter table "member_charges" add constraint "member_charges_invoice_id_foreign" foreign key ("invoice_id") references "invoices" ("id");

-- 2026_10_27_100001_create_obligations_tables.php
create table "obligation_types" ("id" char(26) not null, "organization_id" char(26) null, "catalog_version" varchar(20) not null, "code" varchar(20) not null, "label" varchar(200) not null, "category" varchar(20) not null, "authority" varchar(100) not null, "platform" varchar(50) null, "kind" varchar(20) not null, "periodicity" varchar(20) not null, "due_rule" jsonb not null, "applicability" jsonb not null, "tax_nature" varchar(20) null, "has_abatement" boolean not null default '0', "creates_payment_on_done" boolean not null default '1', "active" boolean not null default '1', "legal_basis" text null, "data_status" varchar(20) not null default 'provisional', "validated_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "obligation_types" add constraint "obligation_types_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
create index "obligation_types_catalog_version_active_index" on "obligation_types" ("catalog_version", "active");
alter table "obligation_types" add primary key ("id");
ALTER TABLE obligation_types ADD CONSTRAINT obligation_types_category_check CHECK (category IN ('tax', 'cnps', 'cmu', 'other'));
ALTER TABLE obligation_types ADD CONSTRAINT obligation_types_kind_check CHECK (kind IN ('declaration', 'payment', 'declaration_payment', 'diligence'));
ALTER TABLE obligation_types ADD CONSTRAINT obligation_types_periodicity_check CHECK (periodicity IN ('monthly', 'quarterly', 'half_yearly', 'yearly', 'one_off', 'on_event'));
ALTER TABLE obligation_types ADD CONSTRAINT obligation_types_tax_nature_check CHECK (tax_nature IN ('patente', 'bic', 'tva', 'tse', 'its', 'airsi', 'igr', 'irvm', 'irc', 'fdfp', 'tee', 'rme', 'cnps', 'cmu', 'other'));
ALTER TABLE obligation_types ADD CONSTRAINT obligation_types_data_status_check CHECK (data_status IN ('provisional', 'validated'));
CREATE UNIQUE INDEX obligation_types_code_unique ON obligation_types (COALESCE(organization_id, ''), code, catalog_version);
create table "member_obligations" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "obligation_type_id" char(26) not null, "source" varchar(20) not null, "status" varchar(20) not null, "confirmed_by" char(26) null, "confirmed_at" timestamp(0) with time zone null, "start_date" date not null, "end_date" date null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "member_obligations" add constraint "member_obligations_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "member_obligations" add constraint "member_obligations_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "member_obligations" add constraint "member_obligations_obligation_type_id_foreign" foreign key ("obligation_type_id") references "obligation_types" ("id");
alter table "member_obligations" add constraint "member_obligations_confirmed_by_foreign" foreign key ("confirmed_by") references "users" ("id");
create index "member_obligations_organization_id_status_index" on "member_obligations" ("organization_id", "status");
create index "member_obligations_member_id_index" on "member_obligations" ("member_id");
alter table "member_obligations" add primary key ("id");
ALTER TABLE member_obligations ADD CONSTRAINT member_obligations_source_check CHECK (source IN ('suggested', 'manual'));
ALTER TABLE member_obligations ADD CONSTRAINT member_obligations_status_check CHECK (status IN ('suggested', 'active', 'removed'));
ALTER TABLE member_obligations ADD CONSTRAINT member_obligations_period CHECK (end_date IS NULL OR end_date >= start_date);
CREATE UNIQUE INDEX member_obligations_one_open ON member_obligations (member_id, obligation_type_id) WHERE status <> 'removed';
create table "obligation_instances" ("id" char(26) not null, "organization_id" char(26) not null, "member_id" char(26) not null, "member_obligation_id" char(26) null, "obligation_type_id" char(26) null, "label" varchar(200) not null, "category" varchar(20) not null, "kind" varchar(20) not null, "authority_name" varchar(150) null, "period_label" varchar(30) not null, "period_start" date not null, "period_end" date not null, "due_date" date not null, "platform" varchar(50) null, "tax_nature" varchar(20) null, "amount_gross" bigint null, "amount_due" bigint null, "amount_paid" bigint not null default '0', "months_covered" smallint null, "status" varchar(30) not null, "member_declared_status" varchar(30) null, "member_declared_at" timestamp(0) with time zone null, "parent_instance_id" char(26) null, "done_at" date null, "reminder_j7_sent_at" timestamp(0) with time zone null, "reminder_j1_sent_at" timestamp(0) with time zone null, "overdue_notified_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "obligation_instances" add constraint "obligation_instances_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "obligation_instances" add constraint "obligation_instances_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "obligation_instances" add constraint "obligation_instances_member_obligation_id_foreign" foreign key ("member_obligation_id") references "member_obligations" ("id");
alter table "obligation_instances" add constraint "obligation_instances_obligation_type_id_foreign" foreign key ("obligation_type_id") references "obligation_types" ("id");
create index "obligation_instances_organization_id_due_date_status_index" on "obligation_instances" ("organization_id", "due_date", "status");
create index "obligation_instances_member_id_category_due_date_index" on "obligation_instances" ("member_id", "category", "due_date");
alter table "obligation_instances" add primary key ("id");
alter table "obligation_instances" add constraint "obligation_instances_parent_instance_id_foreign" foreign key ("parent_instance_id") references "obligation_instances" ("id");
ALTER TABLE obligation_instances ADD CONSTRAINT obligation_instances_category_check CHECK (category IN ('tax', 'cnps', 'cmu', 'other'));
ALTER TABLE obligation_instances ADD CONSTRAINT obligation_instances_kind_check CHECK (kind IN ('declaration', 'payment', 'diligence'));
ALTER TABLE obligation_instances ADD CONSTRAINT obligation_instances_tax_nature_check CHECK (tax_nature IN ('patente', 'bic', 'tva', 'tse', 'its', 'airsi', 'igr', 'irvm', 'irc', 'fdfp', 'tee', 'rme', 'cnps', 'cmu', 'other'));
ALTER TABLE obligation_instances ADD CONSTRAINT obligation_instances_status_check CHECK (status IN ('todo', 'done', 'to_pay', 'partially_paid', 'paid', 'not_established', 'in_progress', 'established', 'cancelled'));
ALTER TABLE obligation_instances ADD CONSTRAINT obligation_instances_member_declared_status_check CHECK (member_declared_status IN ('todo', 'done', 'to_pay', 'partially_paid', 'paid', 'not_established', 'in_progress', 'established', 'cancelled'));
ALTER TABLE obligation_instances ADD CONSTRAINT obligation_instances_period CHECK (period_start <= period_end);
ALTER TABLE obligation_instances ADD CONSTRAINT obligation_instances_amounts CHECK ((amount_gross IS NULL OR amount_gross >= 0) AND (amount_due IS NULL OR amount_due >= 0) AND amount_paid >= 0);
CREATE UNIQUE INDEX obligation_instances_generated_unique ON obligation_instances (member_obligation_id, kind, period_label) WHERE member_obligation_id IS NOT NULL;
create table "obligation_payments" ("id" char(26) not null, "organization_id" char(26) not null, "instance_id" char(26) not null, "member_id" char(26) not null, "tax_nature" varchar(20) not null, "amount" bigint not null, "paid_on" date not null, "source" varchar(20) not null, "external_ref" varchar(100) null, "status" varchar(20) not null, "proof_file_id" char(26) null, "confirmed_by" char(26) null, "confirmed_at" timestamp(0) with time zone null, "recorded_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "obligation_payments" add constraint "obligation_payments_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "obligation_payments" add constraint "obligation_payments_instance_id_foreign" foreign key ("instance_id") references "obligation_instances" ("id");
alter table "obligation_payments" add constraint "obligation_payments_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "obligation_payments" add constraint "obligation_payments_proof_file_id_foreign" foreign key ("proof_file_id") references "files" ("id");
alter table "obligation_payments" add constraint "obligation_payments_confirmed_by_foreign" foreign key ("confirmed_by") references "users" ("id");
alter table "obligation_payments" add constraint "obligation_payments_recorded_by_foreign" foreign key ("recorded_by") references "users" ("id");
alter table "obligation_payments" add constraint "obligation_payments_organization_id_source_external_ref_unique" unique ("organization_id", "source", "external_ref");
create index "obligation_payments_organization_id_status_paid_on_index" on "obligation_payments" ("organization_id", "status", "paid_on");
create index "obligation_payments_tax_nature_paid_on_index" on "obligation_payments" ("tax_nature", "paid_on");
create index "obligation_payments_instance_id_index" on "obligation_payments" ("instance_id");
alter table "obligation_payments" add primary key ("id");
ALTER TABLE obligation_payments ADD CONSTRAINT obligation_payments_tax_nature_check CHECK (tax_nature IN ('patente', 'bic', 'tva', 'tse', 'its', 'airsi', 'igr', 'irvm', 'irc', 'fdfp', 'tee', 'rme', 'cnps', 'cmu', 'other'));
ALTER TABLE obligation_payments ADD CONSTRAINT obligation_payments_source_check CHECK (source IN ('cga_entry', 'member_declaration', 'import', 'compta_flow'));
ALTER TABLE obligation_payments ADD CONSTRAINT obligation_payments_status_check CHECK (status IN ('pending_confirmation', 'confirmed', 'rejected'));
ALTER TABLE obligation_payments ADD CONSTRAINT obligation_payments_amount_positive CHECK (amount > 0);
ALTER TABLE obligation_payments ADD CONSTRAINT obligation_payments_compta_flow_confirmed CHECK (source <> 'compta_flow' OR (status = 'confirmed' AND external_ref IS NOT NULL));
create table "center_obligations" ("id" char(26) not null, "organization_id" char(26) not null, "label" varchar(200) not null, "kind" varchar(30) not null, "due_date" date not null, "amount" bigint null, "status" varchar(20) not null, "notes" text null, "reminder_sent_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "center_obligations" add constraint "center_obligations_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
create index "center_obligations_organization_id_due_date_status_index" on "center_obligations" ("organization_id", "due_date", "status");
alter table "center_obligations" add primary key ("id");
ALTER TABLE center_obligations ADD CONSTRAINT center_obligations_kind_check CHECK (kind IN ('tax', 'cnps', 'cmu', 'agreement_renewal', 'smt', 'other'));
ALTER TABLE center_obligations ADD CONSTRAINT center_obligations_status_check CHECK (status IN ('todo', 'done', 'to_pay', 'paid'));
ALTER TABLE center_obligations ADD CONSTRAINT center_obligations_amount CHECK (amount IS NULL OR amount >= 0);

-- 2026_10_29_100001_add_member_declaration_rejection_to_obligation_instances.php
alter table "obligation_instances" add column "member_declaration_rejection" text null;

-- 2026_10_29_100002_create_closing_campaign_tables.php
create table "closing_campaigns" ("id" char(26) not null, "organization_id" char(26) not null, "fiscal_year" smallint not null, "steps" jsonb not null, "status" varchar(10) not null, "opened_at" timestamp(0) with time zone not null, "closed_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "closing_campaigns" add constraint "closing_campaigns_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "closing_campaigns" add constraint "closing_campaigns_organization_id_fiscal_year_unique" unique ("organization_id", "fiscal_year");
alter table "closing_campaigns" add primary key ("id");
ALTER TABLE closing_campaigns ADD CONSTRAINT closing_campaigns_status_check CHECK (status IN ('open', 'closed'));
create table "closing_campaign_items" ("id" char(26) not null, "organization_id" char(26) not null, "campaign_id" char(26) not null, "member_id" char(26) not null, "portfolio" varchar(10) not null, "deliverable" varchar(30) not null, "step_pieces_at" date null, "step_regularisation_at" date null, "step_finalisation_at" date null, "step_filing_at" date null, "filing_reference" varchar(100) null, "filing_file_id" char(26) null, "assigned_to" char(26) null, "last_reminder_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "closing_campaign_items" add constraint "closing_campaign_items_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "closing_campaign_items" add constraint "closing_campaign_items_campaign_id_foreign" foreign key ("campaign_id") references "closing_campaigns" ("id");
alter table "closing_campaign_items" add constraint "closing_campaign_items_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "closing_campaign_items" add constraint "closing_campaign_items_filing_file_id_foreign" foreign key ("filing_file_id") references "files" ("id");
alter table "closing_campaign_items" add constraint "closing_campaign_items_assigned_to_foreign" foreign key ("assigned_to") references "users" ("id");
alter table "closing_campaign_items" add constraint "closing_campaign_items_campaign_id_member_id_unique" unique ("campaign_id", "member_id");
create index "closing_campaign_items_organization_id_campaign_id_index" on "closing_campaign_items" ("organization_id", "campaign_id");
alter table "closing_campaign_items" add primary key ("id");
ALTER TABLE closing_campaign_items ADD CONSTRAINT closing_campaign_items_portfolio_check CHECK (portfolio IN ('cga', 'cabinet'));
ALTER TABLE closing_campaign_items ADD CONSTRAINT closing_campaign_items_deliverable_check CHECK (deliverable IN ('smt', 'financial_statements'));

-- 2026_11_02_100001_create_quarterly_report_tables.php
create table "cga_people" ("id" char(26) not null, "organization_id" char(26) not null, "kind" varchar(20) not null, "full_name" varchar(200) not null, "nationality" varchar(80) null, "profession" varchar(150) null, "diplomas" varchar(255) null, "function_in_cga" varchar(150) null, "start_date" date null, "practice_structure" varchar(150) null, "practice_function" varchar(150) null, "practice_years" smallint null, "cnps_declared" boolean null, "active" boolean not null default '1', "end_date" date null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "cga_people" add constraint "cga_people_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
create index "cga_people_organization_id_kind_active_index" on "cga_people" ("organization_id", "kind", "active");
alter table "cga_people" add primary key ("id");
ALTER TABLE cga_people ADD CONSTRAINT cga_people_kind_check CHECK (kind IN ('director', 'employee', 'occasional'));
ALTER TABLE cga_people ADD CONSTRAINT cga_people_practice_years CHECK (practice_years IS NULL OR practice_years >= 0);
ALTER TABLE cga_people ADD CONSTRAINT cga_people_end_date CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date);
create table "cga_resources" ("id" char(26) not null, "organization_id" char(26) not null, "category" varchar(30) not null, "quantity" integer not null, "description" text null, "need" text null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "cga_resources" add constraint "cga_resources_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "cga_resources" add constraint "cga_resources_organization_id_category_unique" unique ("organization_id", "category");
alter table "cga_resources" add primary key ("id");
ALTER TABLE cga_resources ADD CONSTRAINT cga_resources_category_check CHECK (category IN ('premises', 'training_rooms', 'furniture', 'computers', 'software', 'projectors', 'vehicles', 'other'));
ALTER TABLE cga_resources ADD CONSTRAINT cga_resources_quantity CHECK (quantity >= 0);
create table "quarterly_reports" ("id" char(26) not null, "organization_id" char(26) not null, "year" smallint not null, "quarter" smallint not null, "status" varchar(20) not null default 'draft', "deadline" date not null, "legal_deadline" date not null, "is_late" boolean not null default '0', "is_legally_late" boolean not null default '0', "reminders_sent" jsonb not null default '[]', "qualitative_analysis" jsonb not null default '{}', "report_date" date null, "regional_directorate_label" varchar(150) null, "content" jsonb not null default '{}', "people_taxes" jsonb not null default '[]', "completeness" jsonb not null default '{}', "current_version" smallint not null default '0', "transmitted_at" timestamp(0) with time zone null, "transmission_mode" varchar(20) null, "transmission_reference" varchar(100) null, "transmitted_by" char(26) null, "acknowledged_at" date null, "acknowledgement_reference" varchar(100) null, "acknowledgement_file_id" char(26) null, "summary" jsonb null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "quarterly_reports" add constraint "quarterly_reports_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "quarterly_reports" add constraint "quarterly_reports_transmitted_by_foreign" foreign key ("transmitted_by") references "users" ("id");
alter table "quarterly_reports" add constraint "quarterly_reports_acknowledgement_file_id_foreign" foreign key ("acknowledgement_file_id") references "files" ("id");
alter table "quarterly_reports" add constraint "quarterly_reports_organization_id_year_quarter_unique" unique ("organization_id", "year", "quarter");
create index "quarterly_reports_year_quarter_status_index" on "quarterly_reports" ("year", "quarter", "status");
alter table "quarterly_reports" add primary key ("id");
ALTER TABLE quarterly_reports ADD CONSTRAINT quarterly_reports_status_check CHECK (status IN ('draft', 'generated', 'transmitted', 'acknowledged'));
ALTER TABLE quarterly_reports ADD CONSTRAINT quarterly_reports_transmission_mode_check CHECK (transmission_mode IN ('physical', 'email', 'other'));
ALTER TABLE quarterly_reports ADD CONSTRAINT quarterly_reports_quarter CHECK (quarter BETWEEN 1 AND 4);
ALTER TABLE quarterly_reports ADD CONSTRAINT quarterly_reports_legal_deadline CHECK (legal_deadline >= deadline);
ALTER TABLE quarterly_reports ADD CONSTRAINT quarterly_reports_transmission CHECK (status NOT IN ('transmitted', 'acknowledged') OR (transmitted_at IS NOT NULL AND transmission_mode IS NOT NULL));
create table "quarterly_report_versions" ("id" char(26) not null, "organization_id" char(26) not null, "report_id" char(26) not null, "version" smallint not null, "reason" text null, "snapshot" jsonb null, "xlsx_document_id" char(26) null, "docx_document_id" char(26) null, "pdf_document_id" char(26) null, "slip_document_id" char(26) null, "generated_at" timestamp(0) with time zone null, "generated_by" char(26) null, "transmitted_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "quarterly_report_versions" add constraint "quarterly_report_versions_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "quarterly_report_versions" add constraint "quarterly_report_versions_report_id_foreign" foreign key ("report_id") references "quarterly_reports" ("id");
alter table "quarterly_report_versions" add constraint "quarterly_report_versions_xlsx_document_id_foreign" foreign key ("xlsx_document_id") references "generated_documents" ("id");
alter table "quarterly_report_versions" add constraint "quarterly_report_versions_docx_document_id_foreign" foreign key ("docx_document_id") references "generated_documents" ("id");
alter table "quarterly_report_versions" add constraint "quarterly_report_versions_pdf_document_id_foreign" foreign key ("pdf_document_id") references "generated_documents" ("id");
alter table "quarterly_report_versions" add constraint "quarterly_report_versions_slip_document_id_foreign" foreign key ("slip_document_id") references "generated_documents" ("id");
alter table "quarterly_report_versions" add constraint "quarterly_report_versions_generated_by_foreign" foreign key ("generated_by") references "users" ("id");
alter table "quarterly_report_versions" add constraint "quarterly_report_versions_report_id_version_unique" unique ("report_id", "version");
alter table "quarterly_report_versions" add primary key ("id");
ALTER TABLE quarterly_report_versions ADD CONSTRAINT quarterly_report_versions_version CHECK (version >= 1);
ALTER TABLE quarterly_report_versions ADD CONSTRAINT quarterly_report_versions_reason CHECK (version = 1 OR reason IS NOT NULL);

-- 2026_11_03_100001_create_messaging_tables.php
create table "notifications" ("id" uuid not null, "type" varchar(255) not null, "notifiable_type" varchar(255) not null, "notifiable_id" char(26) not null, "data" jsonb not null, "read_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
create index "notifications_notifiable_type_notifiable_id_read_at_index" on "notifications" ("notifiable_type", "notifiable_id", "read_at");
alter table "notifications" add primary key ("id");
create table "messaging_providers" ("id" char(26) not null, "channel" varchar(20) not null, "driver" varchar(30) not null, "label" varchar(100) not null, "settings" jsonb not null default '{}', "secrets" text not null, "is_active" boolean not null default '0', "last_test_at" timestamp(0) with time zone null, "last_test_result" text null, "updated_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "messaging_providers" add constraint "messaging_providers_updated_by_foreign" foreign key ("updated_by") references "users" ("id") on delete set null;
alter table "messaging_providers" add primary key ("id");
ALTER TABLE messaging_providers ADD CONSTRAINT messaging_providers_channel_check CHECK (channel IN ('sms', 'whatsapp', 'email'));
ALTER TABLE messaging_providers ADD CONSTRAINT messaging_providers_driver_check CHECK (driver IN ('log', 'orange_ci', 'http_generic', 'whatsapp_cloud', 'smtp'));
CREATE UNIQUE INDEX messaging_providers_active_channel ON messaging_providers (channel) WHERE is_active;
create table "message_logs" ("id" bigserial not null primary key, "organization_id" char(26) null, "campaign_id" char(26) null, "notification_type" varchar(80) null, "channel" varchar(20) not null, "recipient" varchar(150) not null, "user_id" char(26) null, "status" varchar(20) not null, "provider_message_id" varchar(100) null, "cost_units" integer null, "error" text null, "sent_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone not null default CURRENT_TIMESTAMP);
alter table "message_logs" add constraint "message_logs_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "message_logs" add constraint "message_logs_user_id_foreign" foreign key ("user_id") references "users" ("id") on delete set null;
create index "message_logs_organization_id_created_at_index" on "message_logs" ("organization_id", "created_at");
create index "message_logs_campaign_id_index" on "message_logs" ("campaign_id");
ALTER TABLE message_logs ADD CONSTRAINT message_logs_channel_check CHECK (channel IN ('email', 'sms', 'whatsapp'));
ALTER TABLE message_logs ADD CONSTRAINT message_logs_status_check CHECK (status IN ('queued', 'sent', 'delivered', 'failed'));
create table "sms_quotas" ("organization_id" char(26) not null, "month" date not null, "included" integer not null, "extra" integer not null default '0', "used" integer not null default '0', "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "sms_quotas" add constraint "sms_quotas_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "sms_quotas" add primary key ("organization_id", "month");
ALTER TABLE sms_quotas ADD CONSTRAINT sms_quotas_positive CHECK (included >= 0 AND extra >= 0 AND used >= 0);

-- 2026_11_03_100002_create_network_dues_tables.php
create table "network_dues" ("id" char(26) not null, "organization_id" char(26) not null, "year" smallint not null, "base_amount" bigint not null, "prorata_ratio" decimal(4, 3) not null default '1', "amount_due" bigint not null, "amount_paid" bigint not null default '0', "deadline" date not null, "status" varchar(20) not null default 'due', "last_reminder_at" timestamp(0) with time zone null, "reminder_count" smallint not null default '0', "invoice_id" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "network_dues" add constraint "network_dues_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "network_dues" add constraint "network_dues_invoice_id_foreign" foreign key ("invoice_id") references "invoices" ("id");
alter table "network_dues" add constraint "network_dues_organization_id_year_unique" unique ("organization_id", "year");
create index "network_dues_year_status_index" on "network_dues" ("year", "status");
alter table "network_dues" add primary key ("id");
ALTER TABLE network_dues ADD CONSTRAINT network_dues_status_check CHECK (status IN ('due', 'partial', 'paid', 'unpaid'));
ALTER TABLE network_dues ADD CONSTRAINT network_dues_amounts CHECK (base_amount >= 0 AND amount_due >= 0 AND amount_paid >= 0 AND prorata_ratio > 0 AND prorata_ratio <= 1);
create table "network_due_payments" ("id" char(26) not null, "organization_id" char(26) not null, "network_due_id" char(26) not null, "amount" bigint not null, "paid_on" date not null, "method" varchar(20) not null, "reference" varchar(100) null, "proof_file_id" char(26) null, "source" varchar(20) not null, "status" varchar(30) not null, "rejection_reason" text null, "confirmed_by" char(26) null, "confirmed_at" timestamp(0) with time zone null, "receipt_number" varchar(40) null, "receipt_document_id" char(26) null, "created_by" char(26) null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "network_due_payments" add constraint "network_due_payments_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "network_due_payments" add constraint "network_due_payments_network_due_id_foreign" foreign key ("network_due_id") references "network_dues" ("id");
alter table "network_due_payments" add constraint "network_due_payments_proof_file_id_foreign" foreign key ("proof_file_id") references "files" ("id");
alter table "network_due_payments" add constraint "network_due_payments_confirmed_by_foreign" foreign key ("confirmed_by") references "users" ("id");
alter table "network_due_payments" add constraint "network_due_payments_receipt_document_id_foreign" foreign key ("receipt_document_id") references "generated_documents" ("id");
alter table "network_due_payments" add constraint "network_due_payments_created_by_foreign" foreign key ("created_by") references "users" ("id");
create index "network_due_payments_network_due_id_status_index" on "network_due_payments" ("network_due_id", "status");
alter table "network_due_payments" add primary key ("id");
alter table "network_due_payments" add constraint "network_due_payments_receipt_number_unique" unique ("receipt_number");
ALTER TABLE network_due_payments ADD CONSTRAINT network_due_payments_method_check CHECK (method IN ('cash', 'cheque', 'transfer', 'mobile_money', 'other'));
ALTER TABLE network_due_payments ADD CONSTRAINT network_due_payments_source_check CHECK (source IN ('cga_declaration', 'cncga_entry'));
ALTER TABLE network_due_payments ADD CONSTRAINT network_due_payments_status_check CHECK (status IN ('pending_confirmation', 'confirmed', 'rejected'));
ALTER TABLE network_due_payments ADD CONSTRAINT network_due_payments_amount CHECK (amount > 0);
ALTER TABLE network_due_payments ADD CONSTRAINT network_due_payments_declaration_proof CHECK (source <> 'cga_declaration' OR proof_file_id IS NOT NULL);
ALTER TABLE network_due_payments ADD CONSTRAINT network_due_payments_rejection CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL);

-- 2026_11_03_100003_create_message_campaigns_table.php
create table "message_campaigns" ("id" char(26) not null, "organization_id" char(26) not null, "title" varchar(200) not null, "body" text not null, "template_key" varchar(60) null, "channels" jsonb not null, "recipient_filter" jsonb not null, "recipients_count" integer not null, "status" varchar(30) not null, "requested_by" char(26) not null, "approved_by" char(26) null, "approved_at" timestamp(0) with time zone null, "rejection_reason" text null, "sent_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "message_campaigns" add constraint "message_campaigns_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "message_campaigns" add constraint "message_campaigns_requested_by_foreign" foreign key ("requested_by") references "users" ("id");
alter table "message_campaigns" add constraint "message_campaigns_approved_by_foreign" foreign key ("approved_by") references "users" ("id");
create index "message_campaigns_organization_id_created_at_index" on "message_campaigns" ("organization_id", "created_at");
alter table "message_campaigns" add primary key ("id");
ALTER TABLE message_campaigns ADD CONSTRAINT message_campaigns_status_check CHECK (status IN ('draft', 'pending_approval', 'approved', 'sending', 'sent', 'rejected'));
ALTER TABLE message_campaigns ADD CONSTRAINT message_campaigns_count CHECK (recipients_count >= 0);

-- 2026_11_04_100001_create_concerns_tables.php
create table "concerns" ("id" char(26) not null, "organization_id" char(26) not null, "level" varchar(20) not null, "member_id" char(26) null, "author_user_id" char(26) not null, "category" varchar(30) not null, "subject" varchar(200) not null, "status" varchar(20) not null default 'open', "rejection_reason" text null, "transferred_to_concern_id" char(26) null, "transferred_from_concern_id" char(26) null, "target_response_days" smallint not null, "first_response_at" timestamp(0) with time zone null, "last_activity_at" timestamp(0) with time zone not null, "awaiting_reply_since" timestamp(0) with time zone null, "stale_alert_sent_at" timestamp(0) with time zone null, "resolved_at" timestamp(0) with time zone null, "reopen_count" smallint not null default '0', "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "concerns" add constraint "concerns_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "concerns" add constraint "concerns_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "concerns" add constraint "concerns_author_user_id_foreign" foreign key ("author_user_id") references "users" ("id");
create index "concerns_organization_id_level_status_index" on "concerns" ("organization_id", "level", "status");
create index "concerns_member_id_index" on "concerns" ("member_id");
create index "concerns_level_status_awaiting_reply_since_index" on "concerns" ("level", "status", "awaiting_reply_since");
alter table "concerns" add primary key ("id");
alter table "concerns" add constraint "concerns_transferred_to_concern_id_unique" unique ("transferred_to_concern_id");
alter table "concerns" add constraint "concerns_transferred_from_concern_id_unique" unique ("transferred_from_concern_id");
alter table "concerns" add constraint "concerns_transferred_to_concern_id_foreign" foreign key ("transferred_to_concern_id") references "concerns" ("id");
alter table "concerns" add constraint "concerns_transferred_from_concern_id_foreign" foreign key ("transferred_from_concern_id") references "concerns" ("id");
ALTER TABLE concerns ADD CONSTRAINT concerns_level_check CHECK (level IN ('member_to_cga', 'cga_to_cncga'));
ALTER TABLE concerns ADD CONSTRAINT concerns_category_check CHECK (category IN ('tax', 'accounting', 'legal', 'social', 'training', 'administrative', 'public_platforms', 'document_request', 'info_update', 'closing_pieces', 'other'));
ALTER TABLE concerns ADD CONSTRAINT concerns_status_check CHECK (status IN ('open', 'in_review', 'resolved', 'rejected'));
ALTER TABLE concerns ADD CONSTRAINT concerns_member_level CHECK ((level = 'member_to_cga') = (member_id IS NOT NULL));
ALTER TABLE concerns ADD CONSTRAINT concerns_rejection CHECK (status <> 'rejected' OR rejection_reason IS NOT NULL);
ALTER TABLE concerns ADD CONSTRAINT concerns_transfer_levels CHECK ((transferred_to_concern_id IS NULL OR level = 'member_to_cga') AND (transferred_from_concern_id IS NULL OR level = 'cga_to_cncga'));
ALTER TABLE concerns ADD CONSTRAINT concerns_counters CHECK (target_response_days > 0 AND reopen_count >= 0);
create table "concern_messages" ("id" char(26) not null, "organization_id" char(26) not null, "concern_id" char(26) not null, "author_user_id" char(26) not null, "author_side" varchar(20) not null, "body" text not null, "is_internal" boolean not null default '0', "is_system" boolean not null default '0', "copied_from_message_id" char(26) null, "copied_side" varchar(20) null, "created_at" timestamp(0) with time zone not null);
alter table "concern_messages" add constraint "concern_messages_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "concern_messages" add constraint "concern_messages_concern_id_foreign" foreign key ("concern_id") references "concerns" ("id") on delete cascade;
alter table "concern_messages" add constraint "concern_messages_author_user_id_foreign" foreign key ("author_user_id") references "users" ("id");
create index "concern_messages_concern_id_created_at_index" on "concern_messages" ("concern_id", "created_at");
alter table "concern_messages" add primary key ("id");
alter table "concern_messages" add constraint "concern_messages_copied_from_message_id_foreign" foreign key ("copied_from_message_id") references "concern_messages" ("id");
ALTER TABLE concern_messages ADD CONSTRAINT concern_messages_author_side_check CHECK (author_side IN ('requester', 'handler'));
ALTER TABLE concern_messages ADD CONSTRAINT concern_messages_copied_side_check CHECK (copied_side IN ('requester', 'handler'));
ALTER TABLE concern_messages ADD CONSTRAINT concern_messages_internal CHECK (NOT is_internal OR author_side = 'handler');
ALTER TABLE concern_messages ADD CONSTRAINT concern_messages_copy CHECK ((copied_from_message_id IS NULL) = (copied_side IS NULL));
create table "concern_message_files" ("organization_id" char(26) not null, "message_id" char(26) not null, "file_id" char(26) not null);
alter table "concern_message_files" add constraint "concern_message_files_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "concern_message_files" add constraint "concern_message_files_message_id_foreign" foreign key ("message_id") references "concern_messages" ("id") on delete cascade;
alter table "concern_message_files" add constraint "concern_message_files_file_id_foreign" foreign key ("file_id") references "files" ("id");
alter table "concern_message_files" add primary key ("message_id", "file_id");
alter table "concern_message_files" add constraint "concern_message_files_file_id_unique" unique ("file_id");

-- 2026_11_04_100002_add_training_receipt_document_type.php
ALTER TABLE generated_documents DROP CONSTRAINT IF EXISTS generated_documents_type_check;
ALTER TABLE document_templates DROP CONSTRAINT IF EXISTS document_templates_type_check;
ALTER TABLE generated_documents ADD CONSTRAINT generated_documents_type_check CHECK (type IN ('membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx', 'quarterly_report_modern', 'quarterly_report_xlsx', 'report_transmission_slip', 'dues_receipt', 'dues_invoice', 'training_certificate', 'training_receipt', 'statistics_brochure', 'banking_file', 'uploaded'));
ALTER TABLE document_templates ADD CONSTRAINT document_templates_type_check CHECK (type IN ('membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx', 'quarterly_report_modern', 'quarterly_report_xlsx', 'report_transmission_slip', 'dues_receipt', 'dues_invoice', 'training_certificate', 'training_receipt', 'statistics_brochure', 'banking_file'));

-- 2026_11_04_100003_create_trainings_tables.php
create table "trainings" ("id" char(26) not null, "organization_id" char(26) not null, "title" varchar(200) not null, "description" text null, "format" varchar(20) not null, "location" varchar(255) null, "online_url" varchar(500) null, "trainer" varchar(200) null, "starts_at" timestamp(0) with time zone not null, "ends_at" timestamp(0) with time zone not null, "capacity" integer null, "price" bigint not null default '0', "requires_approval" boolean not null default '0', "status" varchar(20) not null default 'draft', "published_at" timestamp(0) with time zone null, "cancellation_reason" text null, "cancelled_at" timestamp(0) with time zone null, "completed_at" timestamp(0) with time zone null, "created_by" char(26) not null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "trainings" add constraint "trainings_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "trainings" add constraint "trainings_created_by_foreign" foreign key ("created_by") references "users" ("id");
create index "trainings_organization_id_status_starts_at_index" on "trainings" ("organization_id", "status", "starts_at");
create index "trainings_status_starts_at_index" on "trainings" ("status", "starts_at");
alter table "trainings" add primary key ("id");
ALTER TABLE trainings ADD CONSTRAINT trainings_format_check CHECK (format IN ('in_person', 'online', 'workshop'));
ALTER TABLE trainings ADD CONSTRAINT trainings_status_check CHECK (status IN ('draft', 'published', 'cancelled', 'completed'));
ALTER TABLE trainings ADD CONSTRAINT trainings_dates CHECK (ends_at >= starts_at);
ALTER TABLE trainings ADD CONSTRAINT trainings_amounts CHECK (price >= 0 AND (capacity IS NULL OR capacity > 0));
ALTER TABLE trainings ADD CONSTRAINT trainings_cancellation CHECK (status <> 'cancelled' OR (cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL));
create table "training_audiences" ("id" bigserial not null primary key, "organization_id" char(26) not null, "training_id" char(26) not null, "audience" varchar(30) not null, "regime" varchar(5) null, "new_member_months" smallint null);
alter table "training_audiences" add constraint "training_audiences_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "training_audiences" add constraint "training_audiences_training_id_foreign" foreign key ("training_id") references "trainings" ("id") on delete cascade;
alter table "training_audiences" add constraint "training_audiences_training_id_audience_regime_unique" unique ("training_id", "audience", "regime");
ALTER TABLE training_audiences ADD CONSTRAINT training_audiences_audience_check CHECK (audience IN ('cga_managers', 'cga_staff', 'cga_accountants', 'all_members', 'members_by_regime', 'new_members'));
ALTER TABLE training_audiences ADD CONSTRAINT training_audiences_regime_check CHECK (regime IN ('RME', 'TEE'));
ALTER TABLE training_audiences ADD CONSTRAINT training_audiences_regime CHECK ((audience = 'members_by_regime') = (regime IS NOT NULL));
ALTER TABLE training_audiences ADD CONSTRAINT training_audiences_new_members CHECK ((audience = 'new_members') = (new_member_months IS NOT NULL AND new_member_months > 0));
create table "training_registrations" ("id" char(26) not null, "organization_id" char(26) not null, "training_id" char(26) not null, "membership_id" char(26) not null, "user_id" char(26) not null, "member_id" char(26) null, "registered_by" char(26) not null, "status" varchar(20) not null, "waitlist_position" integer null, "rejection_reason" text null, "payment_status" varchar(20) not null default 'not_applicable', "paid_on" date null, "payment_method" varchar(20) null, "payment_reference" varchar(100) null, "receipt_number" varchar(40) null, "receipt_document_id" char(26) null, "attendance" varchar(10) null, "certificate_document_id" char(26) null, "evaluation_score" smallint null, "evaluation_comment" text null, "evaluated_at" timestamp(0) with time zone null, "promoted_at" timestamp(0) with time zone null, "reminded_at" timestamp(0) with time zone null, "cancelled_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "training_registrations" add constraint "training_registrations_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "training_registrations" add constraint "training_registrations_training_id_foreign" foreign key ("training_id") references "trainings" ("id");
alter table "training_registrations" add constraint "training_registrations_membership_id_foreign" foreign key ("membership_id") references "memberships" ("id");
alter table "training_registrations" add constraint "training_registrations_user_id_foreign" foreign key ("user_id") references "users" ("id");
alter table "training_registrations" add constraint "training_registrations_member_id_foreign" foreign key ("member_id") references "members" ("id");
alter table "training_registrations" add constraint "training_registrations_registered_by_foreign" foreign key ("registered_by") references "users" ("id");
alter table "training_registrations" add constraint "training_registrations_receipt_document_id_foreign" foreign key ("receipt_document_id") references "generated_documents" ("id");
alter table "training_registrations" add constraint "training_registrations_certificate_document_id_foreign" foreign key ("certificate_document_id") references "generated_documents" ("id");
create index "training_registrations_training_id_status_waitlist_position_index" on "training_registrations" ("training_id", "status", "waitlist_position");
create index "training_registrations_organization_id_training_id_index" on "training_registrations" ("organization_id", "training_id");
create index "training_registrations_user_id_index" on "training_registrations" ("user_id");
alter table "training_registrations" add primary key ("id");
alter table "training_registrations" add constraint "training_registrations_receipt_number_unique" unique ("receipt_number");
ALTER TABLE training_registrations ADD CONSTRAINT training_registrations_status_check CHECK (status IN ('pending_approval', 'registered', 'waitlisted', 'cancelled', 'rejected'));
ALTER TABLE training_registrations ADD CONSTRAINT training_registrations_payment_status_check CHECK (payment_status IN ('not_applicable', 'to_pay', 'paid'));
ALTER TABLE training_registrations ADD CONSTRAINT training_registrations_payment_method_check CHECK (payment_method IN ('cash', 'cheque', 'transfer', 'mobile_money', 'other'));
ALTER TABLE training_registrations ADD CONSTRAINT training_registrations_attendance_check CHECK (attendance IN ('present', 'absent'));
ALTER TABLE training_registrations ADD CONSTRAINT training_registrations_waitlist CHECK ((status = 'waitlisted') = (waitlist_position IS NOT NULL));
ALTER TABLE training_registrations ADD CONSTRAINT training_registrations_paid CHECK (payment_status <> 'paid' OR (paid_on IS NOT NULL AND payment_method IS NOT NULL AND receipt_number IS NOT NULL));
ALTER TABLE training_registrations ADD CONSTRAINT training_registrations_score CHECK (evaluation_score IS NULL OR evaluation_score BETWEEN 1 AND 5);
ALTER TABLE training_registrations ADD CONSTRAINT training_registrations_cancelled CHECK (status <> 'cancelled' OR cancelled_at IS NOT NULL);
CREATE UNIQUE INDEX training_registrations_person_unique ON training_registrations (training_id, user_id) WHERE status <> 'cancelled';

-- 2026_11_04_100004_create_announcements_tables.php
create table "announcements" ("id" char(26) not null, "organization_id" char(26) not null, "title" varchar(200) not null, "body" text not null, "attachment_file_id" char(26) null, "audience" varchar(30) not null, "member_filter" jsonb null, "send_email" boolean not null default '0', "recipients_count" integer not null default '0', "published_at" timestamp(0) with time zone not null, "created_by" char(26) not null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "announcements" add constraint "announcements_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "announcements" add constraint "announcements_attachment_file_id_foreign" foreign key ("attachment_file_id") references "files" ("id");
alter table "announcements" add constraint "announcements_created_by_foreign" foreign key ("created_by") references "users" ("id");
create index "announcements_organization_id_published_at_index" on "announcements" ("organization_id", "published_at");
create index "announcements_audience_published_at_index" on "announcements" ("audience", "published_at");
alter table "announcements" add primary key ("id");
ALTER TABLE announcements ADD CONSTRAINT announcements_audience_check CHECK (audience IN ('all_cgas', 'selected_cgas', 'all_network_members', 'own_members'));
ALTER TABLE announcements ADD CONSTRAINT announcements_member_filter CHECK ((audience = 'own_members') = (member_filter IS NOT NULL));
ALTER TABLE announcements ADD CONSTRAINT announcements_recipients CHECK (recipients_count >= 0);
create table "announcement_targets" ("announcement_id" char(26) not null, "target_organization_id" char(26) not null);
alter table "announcement_targets" add constraint "announcement_targets_announcement_id_foreign" foreign key ("announcement_id") references "announcements" ("id") on delete cascade;
alter table "announcement_targets" add constraint "announcement_targets_target_organization_id_foreign" foreign key ("target_organization_id") references "organizations" ("id");
alter table "announcement_targets" add primary key ("announcement_id", "target_organization_id");
create index "announcement_targets_target_organization_id_index" on "announcement_targets" ("target_organization_id");
create table "announcement_reads" ("announcement_id" char(26) not null, "user_id" char(26) not null, "read_at" timestamp(0) with time zone not null);
alter table "announcement_reads" add constraint "announcement_reads_announcement_id_foreign" foreign key ("announcement_id") references "announcements" ("id") on delete cascade;
alter table "announcement_reads" add constraint "announcement_reads_user_id_foreign" foreign key ("user_id") references "users" ("id");
alter table "announcement_reads" add primary key ("announcement_id", "user_id");

-- 2026_11_05_100001_create_statistics_tables.php
create table "stat_org_daily" ("organization_id" char(26) not null, "day" date not null, "active_members" integer not null, "members_rme" integer not null, "members_tee" integer not null, "members_rsi" integer not null, "new_members" integer not null, "radiations" integer not null, "fees_due" bigint not null, "fees_paid" bigint not null, "tax_paid_total" bigint not null, "tax_paid_by_nature" jsonb not null, "declared_revenue" bigint not null, "members_by_sector" jsonb not null, "members_by_commune" jsonb not null, "concerns_opened" integer not null, "concerns_closed" integer not null, "concerns_responded" integer not null, "concerns_avg_response_hours" decimal(10, 2) null, "computed_at" timestamp(0) with time zone not null);
alter table "stat_org_daily" add constraint "stat_org_daily_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "stat_org_daily" add primary key ("organization_id", "day");
create index "stat_org_daily_day_index" on "stat_org_daily" ("day");
ALTER TABLE stat_org_daily ADD CONSTRAINT stat_org_daily_counts CHECK (active_members >= 0 AND members_rme >= 0 AND members_tee >= 0 AND members_rsi >= 0 AND new_members >= 0 AND radiations >= 0 AND concerns_opened >= 0 AND concerns_closed >= 0 AND concerns_responded >= 0);
ALTER TABLE stat_org_daily ADD CONSTRAINT stat_org_daily_amounts CHECK (fees_due >= 0 AND fees_paid >= 0 AND tax_paid_total >= 0 AND declared_revenue >= 0);
ALTER TABLE stat_org_daily ADD CONSTRAINT stat_org_daily_regimes CHECK (members_rme + members_tee + members_rsi = active_members);
CREATE MATERIALIZED VIEW stat_network_monthly AS
WITH bounds AS (
    SELECT make_date(LEAST(
        COALESCE((SELECT min(year) FROM network_dues), EXTRACT(YEAR FROM CURRENT_DATE)::int),
        COALESCE((SELECT min(year) FROM quarterly_reports), EXTRACT(YEAR FROM CURRENT_DATE)::int),
        EXTRACT(YEAR FROM CURRENT_DATE)::int
    ), 1, 1) AS first_month
),
months AS (
    SELECT series::date AS month, (series + interval '1 month' - interval '1 day')::date AS month_end
    FROM bounds, generate_series(bounds.first_month, date_trunc('month', CURRENT_DATE)::date, interval '1 month') AS series
)
SELECT
    months.month,
    months.month_end,
    (SELECT count(*) FROM organizations o
        WHERE o.type = 'cga' AND o.deleted_at IS NULL AND o.created_at::date <= months.month_end
        AND NOT EXISTS (SELECT 1 FROM organization_status_events e
            WHERE e.organization_id = o.id AND e.event = 'terminated' AND e.occurred_at::date <= months.month_end)
    )::int AS total_cgas,
    (SELECT count(*) FROM organizations o
        WHERE o.type = 'cga' AND o.deleted_at IS NULL AND o.subscription_status <> 'none'
        AND o.subscription_started_at <= months.month_end
        AND (o.subscription_ended_at IS NULL OR o.subscription_ended_at > months.month_end)
    )::int AS subscribed_cgas,
    COALESCE((SELECT sum(d.amount_due) FROM network_dues d
        WHERE d.year = EXTRACT(YEAR FROM months.month)::int), 0)::bigint AS dues_expected,
    COALESCE((SELECT sum(p.amount) FROM network_due_payments p
        WHERE p.status = 'confirmed' AND p.paid_on BETWEEN months.month AND months.month_end), 0)::bigint AS dues_collected,
    COALESCE((SELECT sum(p.amount) FROM network_due_payments p JOIN network_dues d ON d.id = p.network_due_id
        WHERE p.status = 'confirmed' AND d.year = EXTRACT(YEAR FROM months.month)::int AND p.paid_on <= months.month_end), 0)::bigint AS dues_collected_year_to_date,
    (SELECT count(*) FROM network_dues d
        WHERE d.year = EXTRACT(YEAR FROM months.month)::int AND d.status = 'paid')::int AS dues_paid_cgas,
    (SELECT count(*) FROM quarterly_reports r
        WHERE r.deadline BETWEEN months.month AND months.month_end)::int AS reports_due,
    (SELECT count(*) FROM quarterly_reports r
        WHERE r.transmitted_at IS NOT NULL AND r.transmitted_at::date BETWEEN months.month AND months.month_end)::int AS reports_transmitted,
    (SELECT count(*) FROM quarterly_reports r
        WHERE r.transmitted_at IS NOT NULL AND r.transmitted_at::date BETWEEN months.month AND months.month_end
        AND r.transmitted_at::date > r.deadline)::int AS reports_transmitted_late,
    now() AS computed_at
FROM months;
CREATE UNIQUE INDEX stat_network_monthly_month ON stat_network_monthly (month);

-- 2026_11_05_100002_create_exports_table.php
create table "exports" ("id" char(26) not null, "organization_id" char(26) not null, "membership_id" char(26) not null, "user_id" char(26) not null, "type" varchar(30) not null, "period_type" varchar(10) null, "period_from" date null, "period_to" date null, "filters" jsonb not null default '{}', "status" varchar(20) not null default 'queued', "rows_count" integer not null default '0', "file_id" char(26) null, "requested_at" timestamp(0) with time zone not null, "finished_at" timestamp(0) with time zone null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
alter table "exports" add constraint "exports_organization_id_foreign" foreign key ("organization_id") references "organizations" ("id");
alter table "exports" add constraint "exports_membership_id_foreign" foreign key ("membership_id") references "memberships" ("id");
alter table "exports" add constraint "exports_user_id_foreign" foreign key ("user_id") references "users" ("id");
alter table "exports" add constraint "exports_file_id_foreign" foreign key ("file_id") references "files" ("id");
create index "exports_organization_id_membership_id_requested_at_index" on "exports" ("organization_id", "membership_id", "requested_at");
alter table "exports" add primary key ("id");
ALTER TABLE exports ADD CONSTRAINT exports_type_check CHECK (type IN ('members', 'concerns', 'network_concerns', 'training_registrations', 'obligations', 'dues', 'statistics'));
ALTER TABLE exports ADD CONSTRAINT exports_status_check CHECK (status IN ('queued', 'processing', 'done', 'failed'));
ALTER TABLE exports ADD CONSTRAINT exports_period CHECK ((period_from IS NULL) = (period_to IS NULL) AND (period_to IS NULL OR period_to >= period_from));
ALTER TABLE exports ADD CONSTRAINT exports_file CHECK (status <> 'done' OR file_id IS NOT NULL);
ALTER TABLE exports ADD CONSTRAINT exports_rows CHECK (rows_count >= 0);

-- 2026_11_05_100003_add_statistics_brochure_document_type.php
ALTER TABLE generated_documents DROP CONSTRAINT IF EXISTS generated_documents_type_check;
ALTER TABLE document_templates DROP CONSTRAINT IF EXISTS document_templates_type_check;
ALTER TABLE generated_documents ADD CONSTRAINT generated_documents_type_check CHECK (type IN ('membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx', 'quarterly_report_modern', 'quarterly_report_xlsx', 'report_transmission_slip', 'dues_receipt', 'dues_invoice', 'training_certificate', 'training_receipt', 'statistics_brochure', 'banking_file', 'uploaded'));
ALTER TABLE document_templates ADD CONSTRAINT document_templates_type_check CHECK (type IN ('membership_contract', 'membership_certificate', 'receipt', 'invoice', 'credit_note', 'radiation_notice', 'quarterly_report_docx', 'quarterly_report_modern', 'quarterly_report_xlsx', 'report_transmission_slip', 'dues_receipt', 'dues_invoice', 'training_certificate', 'training_receipt', 'statistics_brochure', 'banking_file'));

-- 2026_11_06_100001_create_backup_runs_table.php
create table "backup_runs" ("id" char(26) not null, "status" varchar(20) not null, "started_at" timestamp(0) with time zone not null, "finished_at" timestamp(0) with time zone null, "database_file" varchar(255) null, "database_bytes" bigint null, "files_file" varchar(255) null, "files_level" varchar(20) null, "files_bytes" bigint null, "remote_copied" boolean not null default '0', "error" text null, "created_at" timestamp(0) with time zone null, "updated_at" timestamp(0) with time zone null);
create index "backup_runs_status_finished_at_index" on "backup_runs" ("status", "finished_at");
alter table "backup_runs" add primary key ("id");
ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_status_check CHECK (status IN ('running', 'done', 'failed'));
ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_files_level CHECK (files_level IS NULL OR files_level IN ('full', 'incremental'));
ALTER TABLE backup_runs ADD CONSTRAINT backup_runs_done CHECK (status <> 'done' OR (finished_at IS NOT NULL AND database_file IS NOT NULL AND files_file IS NOT NULL));

-- 2026_11_13_100001_drop_citext_and_pg_trgm.php
DROP INDEX IF EXISTS users_email_unique;
CREATE UNIQUE INDEX users_email_unique ON users (lower(email)) WHERE email IS NOT NULL;

COMMIT;
