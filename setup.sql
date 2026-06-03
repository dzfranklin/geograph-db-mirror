DROP PROCEDURE IF EXISTS add_index_if_not_exists;
DELIMITER //
CREATE PROCEDURE add_index_if_not_exists(tbl VARCHAR(64), col VARCHAR(64))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = tbl
          AND COLUMN_NAME = col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD INDEX (', col, ')');
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL add_index_if_not_exists('gridimage_snippet', 'gridimage_id');
CALL add_index_if_not_exists('gridimage_snippet', 'snippet_id');
CALL add_index_if_not_exists('snippet', 'snippet_id');

CREATE TABLE IF NOT EXISTS gridimage_recent (
    gridimage_id INT NOT NULL,
    point_ll POINT NOT NULL,
    PRIMARY KEY (gridimage_id),
    SPATIAL INDEX (point_ll)
);

CREATE OR REPLACE VIEW gridimage AS
SELECT
    gsearch.*,
    snip.title AS snippet_title,
    snip.comment AS snippet_comment
FROM gridimage_search AS gsearch
LEFT JOIN gridimage_snippet AS gsnip ON gsnip.gridimage_id = gsearch.gridimage_id
LEFT JOIN snippet AS snip ON snip.snippet_id = gsnip.snippet_id;
