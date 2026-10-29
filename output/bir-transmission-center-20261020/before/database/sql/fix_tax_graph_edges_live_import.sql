-- NUERS live import repair for MySQL error #1071 on tax_graph_edges.
-- Run this after the tax_graph_edges table has been imported, before continuing the dump.

ALTER TABLE `tax_graph_edges`
    MODIFY `scope` VARCHAR(64) NOT NULL,
    MODIFY `source` VARCHAR(191) NOT NULL,
    MODIFY `target` VARCHAR(191) NOT NULL,
    MODIFY `relationship` VARCHAR(191) NOT NULL,
    MODIFY `risk_level` VARCHAR(50) NULL;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_graph_edges'
      AND index_name = 'PRIMARY'
);
SET @sql := IF(
    @index_exists = 0,
    'ALTER TABLE `tax_graph_edges` ADD PRIMARY KEY (`id`)',
    'SELECT ''tax_graph_edges primary key already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_graph_edges'
      AND index_name = 'tax_graph_edges_scope_source_target_relationship_unique'
);
SET @sql := IF(
    @index_exists = 0,
    'CREATE UNIQUE INDEX `tax_graph_edges_scope_source_target_relationship_unique` ON `tax_graph_edges` (`scope`, `source`, `target`, `relationship`)',
    'SELECT ''tax_graph_edges unique edge index already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_graph_edges'
      AND index_name = 'tax_graph_edges_scope_index'
);
SET @sql := IF(
    @index_exists = 0,
    'CREATE INDEX `tax_graph_edges_scope_index` ON `tax_graph_edges` (`scope`)',
    'SELECT ''tax_graph_edges scope index already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'tax_graph_edges'
      AND index_name = 'tax_graph_edges_risk_level_index'
);
SET @sql := IF(
    @index_exists = 0,
    'CREATE INDEX `tax_graph_edges_risk_level_index` ON `tax_graph_edges` (`risk_level`)',
    'SELECT ''tax_graph_edges risk level index already exists'' AS message'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
