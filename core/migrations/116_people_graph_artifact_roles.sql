-- 116_people_graph_artifact_roles.sql
--
-- Artifact Graph consumption of People Graph.
--
-- The original People Graph MVP covered ownership, approval, review, and AI
-- supervision. Artifact Graph needs the fuller artifact vocabulary from the
-- product spec: preparer, requester, recipient, and AI creator. Keep this as a
-- forward migration because 112_people_graph.sql may already be deployed.

ALTER TABLE people_graph_responsibility_assignments
    MODIFY responsibility_type ENUM(
        'owner','accountable','preparer','approver','reviewer','requester',
        'recipient','ai_creator','ai_supervisor','notifier','operator',
        'viewer','escalation_contact'
    ) NOT NULL;

ALTER TABLE people_graph_approval_policy_rules
    MODIFY responsibility_type ENUM(
        'owner','accountable','preparer','approver','reviewer','requester',
        'recipient','ai_creator','ai_supervisor','notifier','operator',
        'viewer','escalation_contact'
    ) NULL;
