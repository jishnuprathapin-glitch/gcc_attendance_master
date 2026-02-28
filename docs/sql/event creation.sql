CREATE DEFINER=`root`@`localhost` EVENT `cleanup_audit_logs` ON SCHEDULE EVERY 5 MINUTE STARTS '2026-02-26 14:42:32' ON COMPLETION NOT PRESERVE ENABLE DO DELETE FROM audit_logs
  WHERE id < (
    SELECT keep_id
    FROM (
      SELECT id AS keep_id
      FROM audit_logs
      ORDER BY id DESC
      LIMIT 1 OFFSET 999
    ) t
  )
  
  
  CREATE DEFINER=`root`@`localhost` EVENT `ev_system_override_runner` ON SCHEDULE EVERY 15 MINUTE STARTS '2026-02-26 16:03:59' ON COMPLETION NOT PRESERVE ENABLE DO CALL `sp_system_override_run`()