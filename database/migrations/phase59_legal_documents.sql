-- ============================================================================
-- Phase 59 — Legal document management (Governance → Legal):
--   * legal_documents: Privacy Policy, Terms/Merchant Agreement, Refunds …
--     draft/publish workflow; PUBLISHED versions are served live to payg.in
--     via api/sales/legal-public.php.
--   * legal_document_revisions: every content change snapshotted (compliance
--     trail + one-click restore).
--   * Access: admin via RBAC + the "Compliance & Legal" group (renamed from
--     "Compliance Team", members keep their compliance module access).
-- Idempotent; safe to re-run.
-- ============================================================================

CREATE TABLE IF NOT EXISTS `legal_documents` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `slug` varchar(60) NOT NULL,
  `title` varchar(160) NOT NULL,
  `content` mediumtext,
  `status` enum('draft','published') NOT NULL DEFAULT 'draft',
  `published_at` timestamp NULL DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `legal_document_revisions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `document_id` int(11) NOT NULL,
  `title` varchar(160) NOT NULL,
  `content` mediumtext,
  `status` varchar(12) NOT NULL,
  `saved_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `ix_doc` (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the three site documents (content seeded by seed-legal.php / edited in the panel)
INSERT INTO `legal_documents` (`slug`,`title`,`status`)
SELECT s.slug, s.title, 'draft' FROM (
  SELECT 'merchant-agreement' slug, 'Merchant Agreement & Terms' title UNION ALL
  SELECT 'privacy-policy', 'Privacy Policy' UNION ALL
  SELECT 'refunds-chargebacks', 'Refunds & Chargebacks'
) s
WHERE NOT EXISTS (SELECT 1 FROM `legal_documents` d WHERE d.slug = s.slug);

-- rename the group and widen its mandate
UPDATE `emp_groups` SET `name`='Compliance & Legal',
    `description`='Owns RBI/regulatory compliance AND the public legal documents (privacy, terms, refunds)'
  WHERE `name`='Compliance Team';

INSERT INTO `emp_group_access` (`group_id`,`module_key`,`can_access`)
SELECT g.id,'legal',1 FROM `emp_groups` g
 WHERE g.`name`='Compliance & Legal'
   AND NOT EXISTS (SELECT 1 FROM `emp_group_access` a WHERE a.`group_id`=g.id AND a.`module_key`='legal');

INSERT INTO `role_access` (`role`,`module_key`,`can_access`)
SELECT 'admin','legal',1 FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM `role_access` WHERE `role`='admin' AND `module_key`='legal');
