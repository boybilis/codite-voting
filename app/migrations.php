<?php
// Add profile fields to existing installations without removing election data.
function migrateMemberProfiles(): void {
    $columns = query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='members'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$columns) return; // The first-time installer creates the complete schema.
    if (!one("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='nominee_invitations'")) {
        db()->exec(file_get_contents(__DIR__.'/invitation-schema.sql'));
    }
    foreach (['school'=>160, 'position'=>120] as $column=>$length) {
        if (in_array($column, $columns, true)) continue;
        try { db()->exec("ALTER TABLE members ADD COLUMN $column VARCHAR($length) NOT NULL DEFAULT ''"); }
        catch (PDOException $error) { if (($error->errorInfo[1]??null)!==1060) throw $error; }
    }
}
