<?php

/**
 * @file pages/sitemap/SitemapHandler.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class SitemapHandler
 * @ingroup pages_sitemap
 *
 * @brief Produce a sitemap in XML format for submitting to search engines.
 */

import('lib.pkp.pages.sitemap.PKPSitemapHandler');

class SitemapHandler extends PKPSitemapHandler {

	/**
	 * @copydoc PKPSitemapHandler_createContextSitemap()
	 */
	function _createContextSitemap($request) {
		$doc = parent::_createContextSitemap($request);
		$root = $doc->documentElement;

		$journal = $request->getJournal();
		$journalId = $journal->getId();

		// Search
		$root->appendChild($this->_createUrlTree($doc, $request->url($journal->getPath(), 'search')));
		// Issues
		$issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
		if ($journal->getData('publishingMode') != PUBLISHING_MODE_NONE) {
			$root->appendChild($this->_createUrlTree($doc, $request->url($journal->getPath(), 'issue', 'current')));
			$root->appendChild($this->_createUrlTree($doc, $request->url($journal->getPath(), 'issue', 'archive')));

			$publishedIssuesResult = $issueDao->retrieve(
				'SELECT i.issue_id
				FROM issues i
				LEFT JOIN custom_issue_orders o ON (o.issue_id = i.issue_id)
				WHERE i.journal_id = ? AND i.published = 1
				ORDER BY o.seq ASC, i.current DESC, i.date_published DESC',
				[(int) $journalId]
			);
			$issueIds = [];
			while ($issueRow = $publishedIssuesResult->current()) {
				$issueId = (int) $issueRow->issue_id;
				$issueIds[] = $issueId;
				$publishedIssuesResult->next();
			}

			if (!empty($issueIds)) {
				$publishedSubmissionsResult = $issueDao->retrieve(
					'SELECT i.issue_id,
						s.submission_id,
						COALESCE(NULLIF(p.url_path, \'\'), CAST(s.submission_id AS CHAR(20))) AS article_best_id,
						COALESCE(NULLIF(g.url_path, \'\'), CAST(g.galley_id AS CHAR(20))) AS galley_best_id
					FROM issues i
					LEFT JOIN custom_issue_orders o ON (o.issue_id = i.issue_id)
					INNER JOIN publication_settings ps ON (
						ps.setting_value = CAST(i.issue_id AS CHAR(20))
						AND ps.setting_name = ?
						AND ps.locale = \'\'
					)
					INNER JOIN publications p ON (p.publication_id = ps.publication_id)
					INNER JOIN submissions s ON (s.current_publication_id = p.publication_id)
					LEFT JOIN publication_galleys g ON (g.publication_id = p.publication_id)
					WHERE i.journal_id = ?
						AND i.published = 1
						AND s.context_id = ?
						AND s.status = ?
					ORDER BY o.seq ASC, i.current DESC, i.date_published DESC, s.date_submitted DESC, g.seq ASC',
					['issueId', (int) $journalId, (int) $journalId, (int) STATUS_PUBLISHED]
				);

				$publishedRowsByIssue = [];
				while ($publishedSubmissionRow = $publishedSubmissionsResult->current()) {
					$publishedRowsByIssue[$publishedSubmissionRow->issue_id][] = $publishedSubmissionRow;
					$publishedSubmissionsResult->next();
				}

				foreach ($issueIds as $issueId) {
					$root->appendChild($this->_createUrlTree($doc, $request->url($journal->getPath(), 'issue', 'view', $issueId)));
					$issueRows = $publishedRowsByIssue[(string) $issueId] ?? [];
					$addedArticles = [];
					foreach ($issueRows as $issueRow) {
						$articleBestId = $issueRow->article_best_id;
						$articleKey = (string) $issueRow->submission_id;

						if (!isset($addedArticles[$articleKey])) {
							$root->appendChild($this->_createUrlTree($doc, $request->url($journal->getPath(), 'article', 'view', [$articleBestId])));
							$addedArticles[$articleKey] = true;
						}

						if ($issueRow->galley_best_id !== null) {
							$root->appendChild($this->_createUrlTree($doc, $request->url($journal->getPath(), 'article', 'view', [$articleBestId, $issueRow->galley_best_id])));
						}
					}
				}
			}
		}

		$doc->appendChild($root);

		// Enable plugins to change the sitemap
		HookRegistry::call('SitemapHandler::createJournalSitemap', array(&$doc));

		return $doc;
	}

}
