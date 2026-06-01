ALTER TABLE gridimage_snippet ADD INDEX IF NOT EXISTS (gridimage_id);
ALTER TABLE gridimage_snippet ADD INDEX IF NOT EXISTS (snippet_id);

CREATE OR REPLACE VIEW gridimage AS
SELECT
    gsearch.*,
    snip.title AS snippet_title,
    snip.comment AS snippet_comment
FROM gridimage_search AS gsearch
LEFT JOIN gridimage_snippet AS gsnip ON gsnip.gridimage_id = gsearch.gridimage_id
LEFT JOIN snippet AS snip ON snip.snippet_id = gsnip.snippet_id;
