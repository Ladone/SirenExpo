<?php

/**
 * @file plugins/importexport/native/SirenExpoImportExportPlugin.inc.php
 *
 * Copyright (c) 2014-2017 Simon Fraser University
 * Copyright (c) 2003-2017 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class NativeImportExportPlugin
 * @ingroup plugins_importexport_native
 *
 * @brief Native XML import/export plugin
 */

import('lib.pkp.classes.plugins.ImportExportPlugin');
import('lib.pkp.classes.submission.SubmissionFile');

class SirenExpoImportExportPlugin extends ImportExportPlugin {

	/**
	 * Called as a plugin is registered to the registry
	 * @param $category String Name of category plugin was registered to
	 * @param $path string
	 * @return boolean True iff plugin initialized successfully; if false,
	 * 	the plugin will not be registered.
	 */
	function register($category, $path) {
		$success = parent::register($category, $path);
		$this->addLocaleData();
		$this->import('SirenExpoImportExportDeployment');
		return $success;
	}

	/**
	 * @copydoc Plugin::getTemplatePath($inCore)
	 */
	function getTemplatePath($inCore = false) {
		return parent::getTemplatePath($inCore) . 'templates/';
	}

	/**
	 * Get the name of this plugin. The name must be unique within
	 * its category.
	 * @return String name of plugin
	 */
	function getName() {
		return 'SirenExpoImportExportPlugin';
	}

	/**
	 * Get the display name.
	 * @return string
	 */
	function getDisplayName() {
		return __('plugins.importexport.sirenexpo.displayName');
	}

	/**
	 * Get the display description.
	 * @return string
	 */
	function getDescription() {
		return __('plugins.importexport.sirenexpo.description');
	}

	/**
	 * @copydoc ImportExportPlugin::getPluginSettingsPrefix()
	 */
	function getPluginSettingsPrefix() {
		return 'sirenexpo';
	}

	/**
	 * Display the plugin.
	 * @param $args array
	 * @param $request PKPRequest
	 */
	function display($args, $request) {
		parent::display($args, $request);
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('test', $request);
		$journal = $request->getJournal();
		switch (array_shift($args)) {
			case 'index':
			case '':
				import('lib.pkp.controllers.list.submissions.SelectSubmissionsListHandler');
				$exportSubmissionsListHandler = new SelectSubmissionsListHandler(array(
					'title' => 'plugins.importexport.sirenexpo.exportSubmissionsSelect',
					'count' => 100,
					'inputName' => 'selectedSubmissions[]',
					'lazyLoad' => true,
				));
				$templateMgr->assign('exportSubmissionsListData', json_encode($exportSubmissionsListHandler->getConfig()));
				$templateMgr->display($this->getTemplatePath() . 'index.tpl');
				break;
			case 'uploadImportXML':
				$user = $request->getUser();
				import('lib.pkp.classes.file.TemporaryFileManager');
				$temporaryFileManager = new TemporaryFileManager();
				$temporaryFile = $temporaryFileManager->handleUpload('uploadedFile', $user->getId());
				if ($temporaryFile) {
					$json = new JSONMessage(true);
					$json->setAdditionalAttributes(array(
						'temporaryFileId' => $temporaryFile->getId()
					));
				} else {
					$json = new JSONMessage(false, __('common.uploadFailed'));
				}

				return $json->getString();
			case 'importBounce':
				$json = new JSONMessage(true);
				$json->setEvent('addTab', array(
					'title' => __('plugins.importexport.native.results'),
					'url' => $request->url(null, null, null, array('plugin', $this->getName(), 'import'), array('temporaryFileId' => $request->getUserVar('temporaryFileId'))),
				));
				return $json->getString();
			case 'import':
				AppLocale::requireComponents(LOCALE_COMPONENT_PKP_SUBMISSION);
				$temporaryFileId = $request->getUserVar('temporaryFileId');
				$temporaryFileDao = DAORegistry::getDAO('TemporaryFileDAO');
				$user = $request->getUser();
				$temporaryFile = $temporaryFileDao->getTemporaryFile($temporaryFileId, $user->getId());
				if (!$temporaryFile) {
					$json = new JSONMessage(true, __('plugins.inportexport.sirenexpo.uploadFile'));
					return $json->getString();
				}
				$temporaryFilePath = $temporaryFile->getFilePath();

				$filter = 'native-xml=>rinz';
				// is this articles import:
				$xmlString = file_get_contents($temporaryFilePath);
				$document = new DOMDocument();
				$document->loadXml($xmlString);
				if (in_array($document->documentElement->tagName, array('article', 'articles'))) {
					$filter = 'native-xml=>article';
				}

				$deployment = new NativeImportExportDeployment($journal, $user);

				libxml_use_internal_errors(true);
				$content = $this->importSubmissions(file_get_contents($temporaryFilePath), $filter, $deployment);
				$templateMgr->assign('content', $content);
				$validationErrors = array_filter(libxml_get_errors(), create_function('$a', 'return $a->level == LIBXML_ERR_ERROR ||  $a->level == LIBXML_ERR_FATAL;'));
				$templateMgr->assign('validationErrors', $validationErrors);
				libxml_clear_errors();

				// Are there any import warnings? Display them.
				$warningTypes = array(
					ASSOC_TYPE_ISSUE => 'issuesWarnings',
					ASSOC_TYPE_SUBMISSION => 'submissionsWarnings',
					ASSOC_TYPE_SECTION => 'sectionWarnings',
				);
				foreach ($warningTypes as $assocType => $templateVar) {
					$foundWarnings = $deployment->getProcessedObjectsWarnings($assocType);
					if (!empty($foundWarnings)) {
						$templateMgr->assign($templateVar, $foundWarnings);
					}
				}

				// Are there any import errors? Display them.
				$errorTypes = array(
					ASSOC_TYPE_ISSUE => 'issuesErrors',
					ASSOC_TYPE_SUBMISSION => 'submissionsErrors',
					ASSOC_TYPE_SECTION => 'sectionErrors',
				);
				$foundErrors = false;
				foreach ($errorTypes as $assocType => $templateVar) {
					$currentErrors = $deployment->getProcessedObjectsErrors($assocType);
					if (!empty($currentErrors)) {
						$templateMgr->assign($templateVar, $currentErrors);
						$foundErrors = true;
					}
				}
				// If there are any data or validataion errors
				// delete imported objects.
				if ($foundErrors || !empty($validationErrors)) {
					// remove all imported issues and sumissions
					foreach (array_keys($errorTypes) as $assocType) {
						$deployment->removeImportedObjects($assocType);
					}
				}
				// Display the results
				$json = new JSONMessage(true, $templateMgr->fetch($this->getTemplatePath() . 'results.tpl'));
				return $json->getString();
			case 'exportSubmissions':
				$exportXml = $this->exportSubmissions(
					(array) $request->getUserVar('selectedSubmissions'),
					$request->getContext(),
					$request->getUser()
				);
				import('lib.pkp.classes.file.FileManager');
				$fileManager = new FileManager();
				$exportFileName = $this->getExportFileName($this->getExportPath(), 'articles', $journal, '.xml');
				$fileManager->writeFile($exportFileName, $exportXml);
				$fileManager->downloadFile($exportFileName);
				$fileManager->deleteFile($exportFileName);
				break;
			case 'exportIssues':

				// import libraries
				import('lib.pkp.classes.file.FileManager');
				import('classes.issue.IssueFile');
				import('classes.issue.IssueDAO');
				import('classes.issue.IssueGalleyDAO');
                import('classes.article.PublishedArticleDAO');

                $fileManager = new FileManager();
//				import('classes.rinz.IssuesDAO');
//				$rinz = new IssueDAO();
//				$issueDAOF = new IssueFileDAO();
//				$rinz->getById($request->getUserVar('selectedIssues')[0]);
//				print_r($rinz);
//				print_r($issueDAOF->getById($request->getUserVar('selectedIssues')[0]));
//				echo 'ok';

//				$issueId = $request->getUserVar('selectedIssues')[0];
//				$articles = new PublishedArticleDAO();
//				var_dump($issueId);
//				var_dump($articles->getPublishedArticles($issueId)[0]->getData('galleys'));
////				var_dump($articles->getPublishedArticles($issueId)[0]->getData('galleys')[0]->getFileId());
//
//				// get file with neris <3
//				$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */
//                $file = $articles->getPublishedArticles($issueId)[0]->getData('galleys')[0];
//                $sourceRevision = $submissionFileDao->getLatestRevisionNumber($file->getFileId());
//                $sourceFile = $submissionFileDao->getRevision($file->getFileId(), $sourceRevision); /* @var $sourceFile SubmissionFile */
//
//                var_dump($sourceFile->getFilePath());
                /** Получение файла выпуска
                $im = new IssueFileManager($request->getUserVar('selectedIssues')[0]);
                $g = new IssueGalleyDAO();
				$g->getById($request->getUserVar('selectedIssues')[0]);
				$filePath = $im->getFilesDir().'public/'.$g->getById($request->getUserVar('selectedIssues')[0])->getData('fileName');
				$fileManager->downloadFile($filePath);
				 **/
/*
				$issue = new Issue();
				$iDAO = new IssueDAO();
				var_dump($iDAO->getById($issueId)->getAllData());
                $this->exportSirenExpo($iDAO->getById($issueId));*/

//				print_r($iDAO->);

//                print_r($filePath);
//				$im->downloadFile($filePath);
//				$exportFileName = $this->getExportFileName($this->getExportPath(), 'issues', $journal, '.xml');
//				$fileManager->writeFile($exportFileName, $exportXml);
//				$fileManager->downloadFile($exportFileName);
//				$fileManager->deleteFile($exportFileName);
//                $fm->downloadFile();
//				var_dump($this->getExportPath());
                $dao = DAORegistry::getDAO('SubmissionKeywordDAO');
                $journalSettingsDAO = DAORegistry::getDAO('JournalDAO');


                // white code
                $issueId = $request->getUserVar('selectedIssues')[0];
                $articlesDAO = new PublishedArticleDAO();
//                var_dump($issueId);
                $articles = $articlesDAO->getPublishedArticles($issueId);
//                var_dump($articles/*[0]->getData('galleys')*/);

                // handle articles
				$submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO'); /* @var $submissionFileDao SubmissionFileDAO */
				$issueDao = new IssueDAO(); /* @var $submissionFileDao SubmissionFileDAO */
				$prepareArticles = [];

                $issue = $issueDao->getById($issueId);
//                $journal->getById($issue->getJournalId());
                $journal = $request->getJournal();
//                print_r($journal);

                foreach($journal->getData('name') as $locale=>$name)
                {
                    $localeName = $locale;
                    switch ($localeName)
                    {
                        case "uk_UA": $localeName = "UKR"; break;
                        case "ru_RU": $localeName = "RUS"; break;
                        case "en_US": $localeName = "ENG"; break;
                        default: $localeName = "ENG"; break;
                    }
                    $prepareArticles['journal']['name'][$localeName] = $name;
                }


                $prepareArticles['journal']['onlineIssn'] = $journal->getData('onlineIssn');
                $prepareArticles['journal']['printIssn'] = $journal->getData('printIssn');

                $prepareArticles['issue']['volume'] = $issue->getVolume();
                $prepareArticles['issue']['number'] = $issue->getNumber();
                $prepareArticles['issue']['altNumber'] = $issue->getVolume();

//                print_r($issue->getTitle());

                foreach($issue->getTitle() as $locale=>$title)
				{
                    $localeTitle = $locale;
                    switch ($locale)
                    {
                        case "uk_UA": $prepareArticles['issue']['issTitle'] = $title; break;
                        case "ru_RU": $prepareArticles['issue']['issTitle'] = $title; break;
                        case "en_US": $prepareArticles['issue']['issTitle'] = $title; break;
                    }
				}

//                $prepareArticles['issue']['issTitle'];
//                print_r($issue);

                foreach($articles as $article)
				{
//					var_dump($article);

					foreach($article->getAuthors() as $author)
					{
//						print_r($author);
//						print_r($author->getLastName());
                        $locale = "";
                        foreach($author->getBiography() as $locale=>$biography)
                        {
                            $locale = $locale;
                            switch ($locale)
                            {
                                case "uk_UA": $locale = "UKR"; break;
                                case "ru_RU": $locale = "RUS"; break;
                                case "en_US": $locale = "ENG"; break;
                                default: $locale = "ENG"; break;
                            }
                            $prepareArticles['issue']['articles'][$article->getData('id')]['article']['authors'][$author->getId()][$locale]['otherInfo'] = strip_tags($biography);
                            $prepareArticles['issue']['articles'][$article->getData('id')]['article']['authors'][$author->getId()][$locale]['firstname'] = $author->getFirstName();
                            $prepareArticles['issue']['articles'][$article->getData('id')]['article']['authors'][$author->getId()][$locale]['middlename'] = $author->getMiddleName();
                            $prepareArticles['issue']['articles'][$article->getData('id')]['article']['authors'][$author->getId()][$locale]['lastname'] = $author->getLastName();
                            $prepareArticles['issue']['articles'][$article->getData('id')]['article']['authors'][$author->getId()][$locale]['email'] = $author->getEmail();
                            $prepareArticles['issue']['articles'][$article->getData('id')]['article']['authors'][$author->getId()][$locale]['locale'] = $locale;

                        }



                    }

//                    var_dump("after article dump\n__________________________________________");

                    $galley = $article->getData('galleys')[0];
//                    print_r($galley);
					$sourceRevision = $submissionFileDao->getLatestRevision($galley->getFileId());
                    $file = $submissionFileDao->getRevision($galley->getFileId(), $sourceRevision); /* @var $sourceFile SubmissionFile */

                    $supportedLocales = array_keys(AppLocale::getSupportedFormLocales());
                    $articleKeywords = $dao->getKeywords($galley->getSubmissionId(), $supportedLocales);

                    foreach($articleKeywords as $locale=>$keywords)
					{
                        $localeKeyword = $galley->getLocale();
                        switch ($localeKeyword)
                        {
                            case "uk_UA": $localeKeyword = "UKR"; break;
                            case "ru_RU": $localeKeyword = "RUS"; break;
                            case "en_US": $localeKeyword = "ENG"; break;
                            default: $localeKeyword = "ENG"; break;
                        }
                        $prepareArticles['issue']['articles'][$article->getData('id')]['article']['keywords'] = $keywords;
					}

                    // Prepare data for XML
					// convert language
                    $localeGalley = $galley->getLocale();
                    switch ($localeGalley)
					{
						case "uk_UA": $localeGalley = "UKR"; break;
						case "ru_RU": $localeGalley = "RUS"; break;
						case "en_US": $localeGalley = "ENG"; break;
						default: $localeGalley = "ENG"; break;
					}

					// prepare article data
                    $prepareArticles['issue']['articles'][$article->getData('id')]['article']['pages'] = $article->getData('pages');

                    // prepare galley data
                    $prepareArticles['issue']['articles'][$article->getData('id')]['galleys'][$galley->getFileId()]['locale'] = $localeGalley;
					$prepareArticles['issue']['articles'][$article->getData('id')]['galleys'][$galley->getFileId()]['references'] = explode("\n", $article->getData('citations'));
                    $prepareArticles['issue']['articles'][$article->getData('id')]['galleys'][$galley->getFileId()]['file_path'] = $file->getFilePath();

                    // prepare abstract description
                    foreach	($article->getAbstract() as $language=>$value)
					{
                        $abstractLanguage = $language;
                        switch ($language)
                        {
                            case "uk_UA": $abstractLanguage = "UKR"; break;
                            case "ru_RU": $abstractLanguage = "RUS"; break;
                            case "en_US": $abstractLanguage = "ENG"; break;
                            default: $abstractLanguage = "ENG"; break;
                        }
                        $prepareArticles['issue']['articles'][$article->getData('id')]['article']['abstract'][$abstractLanguage] = strip_tags($value);

                    }

                    // prepare title
                    foreach	($article->getTitle() as $language=>$value)
                    {
                        $titleLanguage = $language;
                        switch ($language)
                        {
                            case "uk_UA": $titleLanguage = "UKR"; break;
                            case "ru_RU": $titleLanguage = "RUS"; break;
                            case "en_US": $titleLanguage = "ENG"; break;
                            default: $titleLanguage = "ENG"; break;
                        }
                        $prepareArticles['issue']['articles'][$article->getData('id')]['article']['artTitles'][$titleLanguage] = $value;

                    }
//					$skdao = new SubmissionKeywordDAO();

//                    $skdao->_build();

//					var_dump($galley->getSubmissionId());
//					$sourceRevision = $submissionFileDao->getLatestRevision($galley->getFileId());
//                    $file = $submissionFileDao->getRevision($galley->getFileId(), $sourceRevision); /* @var $sourceFile SubmissionFile */
//					var_dump($file->getFilePath());
//					var_dump($issueId);
//					var_dump($this->getExportPath().'tempSirenExpo/');
//					$fileManager->downloadFile($file->getFilePath());
//					copy($file->getFilePath(), $this->getExportPath().'tempSirenExpo/neris.file');
//					var_dump($file->getOriginalFileName());
//					$datetime = new DateTime('now');
//					$date = $datetime->format('HidmY');

//					var_dump($prepareArticles[$issueId][$galley->]);

//					$fileManager->copyFile($file->getFilePath(), $this->getExportPath().'tempSirenExpo/'.$date.'_'.$file->getOriginalFileName());
					/*$file = $article->getData('galleys');
                    $sourceRevision = $submissionFileDao->getLatestRevisionNumber($file->getFileId());
                	$sourceFile = $submissionFileDao->getRevision($file->getFileId(), $sourceRevision); /* @var $sourceFile SubmissionFile */
//					var_dump($sourceFile->getFilePath());*/
				}
                $iDAO = new IssueDAO();
                $this->exportSirenExpo($prepareArticles);
//                print_r($prepareArticles);
//                echo $this->generateXml(1);

                break;
			default:
				$dispatcher = $request->getDispatcher();
				$dispatcher->handle404();
		}
	}


	function exportSirenExpo($prepareData)
	{
		$fm = new FileManager();

//		print_r($prepareData);
		$files = [];

		$issueName = str_replace(" ", "_", $prepareData['issue']['issTitle']);
		$issn = str_replace("-", "", $prepareData['journal']['printIssn']);
		$xmlName = $issn.'_'.date("Y_m_d").'('.$prepareData['issue']['volume'].')_unicode.xml';
//		print_r($xmlName);
//		print_r($issueName);
		$date = new DateTime('now');
//		print_r($date->format("dmy_H:i"));
		$generateName = $issueName.'_'.$date->format("dmy_His");
//		print_r($generateName);

		foreach($prepareData['issue']['articles'] as $article)
		{
			$galleys = $article['galleys'];

			foreach($galleys as $galley)
			{
                $files[] = $galley['file_path'];
			}
		}


        $zip = new ZipArchive();

        if ($zip->open($this->getExportPath().'tempSirenExpo/'.$generateName.'.zip', ZipArchive::CREATE)!==TRUE) {
            exit("Невозможно открыть <$generateName>\n");
        }

        foreach($files as $file)
		{
			$fm->copyFile($file, $this->getExportPath().'tempSirenExpo/'.$generateName.'/'.basename($file));
		}

//		print_r(scandir($this->getExportPath().'tempSirenExpo/'.$generateName.'/'));

		$fm->writeFile($this->getExportPath().'tempSirenExpo/'.$generateName.'/'.$xmlName, $this->generateXml($prepareData));

        $filesInDir = scandir($this->getExportPath().'tempSirenExpo/'.$generateName.'/');

        foreach($filesInDir as $file) {
            if (is_file($this->getExportPath().'tempSirenExpo/'.$generateName.'/' . $file)) {
                $zip->addFile($this->getExportPath().'tempSirenExpo/'.$generateName.'/'.$file, basename($file));
            }
        }
        $zip->addFile($this->getExportPath().'tempSirenExpo/'.$generateName.'/'.$xmlName, $xmlName);
        $zip->close();

		$fm->downloadFile($this->getExportPath().'tempSirenExpo/'.$generateName.'.zip');

        $fm->deleteFile($this->getExportPath().'tempSirenExpo/'.$generateName.'.zip');

        foreach($filesInDir as $file) {
            if (is_file($this->getExportPath().'tempSirenExpo/'.$generateName.'/' . $file)) {
                $fm->deleteFile($this->getExportPath().'tempSirenExpo/'.$generateName.'/'.$file, basename($file));
            }
        }

        $fm->rmdir($this->getExportPath().'tempSirenExpo/'.$generateName.'/');
    }

	function generateXml($prepareData)
	{
//		print_r($prepareData);

		$issue = $prepareData['issue'];
		$articles = $prepareData['issue']['articles'];
		$lang = new AppLocale();
		$xml = "<?xml version='1.0' standalone='no' ?>\n";
        $xml .= "<journal>\n";

		// opercard
		$xml .= "\t<operCard>\n";
		$xml .= "\t\t<operator></operator>\n";
		$xml .= "\t\t<pid></pid>\n";
		$xml .= "\t\t<date></date>\n";
		$xml .= "\t\t<cntArticle></cntArticle>\n";
		$xml .= "\t\t<cntNode></cntNode>\n";
		$xml .= "\t\t<cs></cs>\n";
		$xml .= "\t</operCard>\n";

		// journal
        $xml .= "\t<titleid></titleid>\n";
        $xml .= "\t<issn>".$prepareData['journal']['printIssn']."</issn>\n";
        $xml .= "\t<eissn>".$prepareData['journal']['printIssn']."</eissn>\n";

        foreach ($prepareData['journal']['name'] as $lang=>$value)
		{
            $xml .= "\t<journalInfo lang=\"".$lang."\">\n";
            $xml .= "\t\t<title>".$value."</title>\n";
            $xml .= "\t</journalInfo>\n";
		}
        $xml .= "\t<issue>\n";
        $xml .= "\t\t<volume>".$issue['volume']."</volume>\n";
        $xml .= "\t\t<number>".$issue['number']."</number>\n";
        $xml .= "\t\t<altNumber>".$issue['altNumber']."</altNumber>\n";
        $xml .= "\t\t<part></part>\n";
        $xml .= "\t\t<dateUni></dateUni>\n";
        $xml .= "\t\t<issTitle>".$issue['issTitle']."</issTitle>\n";
        $xml .= "\t\t<pages></pages>\n";

        // articles
        $xml .= "\t\t<articles>\n";


        foreach ($articles as $key=>$article)
		{
            $xml .= "\t\t\t<article>\n";
            $xml .= "\t\t\t\t<pages>".$article['article']['pages']."</pages>\n";
            $xml .= "\t\t\t\t<artType></artType>\n";

            $xml .= "\t\t\t\t<authors>\n";
            foreach($article['article']['authors'] as $authors)
			{
//                $author = $author['author'];
				$index = 1;
				foreach($authors as $lang=>$author)
				{
                    $xml .= "\t\t\t\t\t<author num=\"".sprintf("%03d", $index)."\">\n";
					$xml .= "\t\t\t\t\t\t<individInfo lang=\"".$lang."\">\n";
					$xml .= "\t\t\t\t\t\t\t<surname>".$author['lastname']."</surname>\n";
					$xml .= "\t\t\t\t\t\t\t<initials>".$author['firstname']." ".$author['middlename']."</initials>\n";
					$xml .= "\t\t\t\t\t\t\t<orgName></orgName>\n";
					$xml .= "\t\t\t\t\t\t\t<email>".$author['email']."</email>\n";
					$xml .= "\t\t\t\t\t\t\t<otherInfo>".$author['otherInfo']."</otherInfo>\n";
					$xml .= "\t\t\t\t\t\t</individInfo>\n";
					$xml .= "\t\t\t\t\t</author>\n";
					$index++;
                }
			}
            $xml .= "\t\t\t\t</authors>\n";
            $xml .= "\t\t\t\t<artTitles>\n";
            foreach($article['article']['artTitles'] as $lang=>$title)
			{
                $xml .= "\t\t\t\t\t<artTitle lang=\"".$lang."\">".$title."</artTitle>\n";

            }
            $xml .= "\t\t\t\t</artTitles>\n";
            $xml .= "\t\t\t\t<abstracts>\n";
            foreach($article['article']['abstract'] as $lang=>$abstract)
            {
                $xml .= "\t\t\t\t\t<abstract lang=\"".$lang."\">".$abstract."</abstract>\n";

            }
            $xml .= "\t\t\t\t</abstracts>\n";
            $xml .= "\t\t\t\t<text>\n";
            $xml .= "\t\t\t\t</text>\n";

            $xml .= "\t\t\t\t<codes>\n";
            $xml .= "\t\t\t\t\t<udk></udk>\n";
            $xml .= "\t\t\t\t</codes>\n";

            $xml .= "\t\t\t\t<keywords>\n";
            $xml .= "\t\t\t\t\t<kwdGroup lang=\"ANY\">\n";
            foreach ($article['article']['keywords'] as $keyword)
			{
                $xml .= "\t\t\t\t\t\t<keyword>".$keyword."</keyword>\n";
			}

            $xml .= "\t\t\t\t\t</kwdGroup>\n";
            $xml .= "\t\t\t\t</keywords>\n";

            $xml .= "\t\t\t\t<references>\n";
            foreach ($article['galleys'] as $galley)
			{
				foreach ($galley['references'] as $reference)
				{
                    $xml .= "\t\t\t\t\t<reference>".trim($reference)."</reference>\n";
				}
			}
            $xml .= "\t\t\t\t</references>\n";

            $xml .= "\t\t\t\t<files>\n";
            foreach($article['galleys'] as $galley)
			{
				$filename = basename($galley['file_path']);
                $xml .= "\t\t\t\t\t<file>".$filename."</file>\n";
			}
            $xml .= "\t\t\t\t</files>\n";

            $xml .= "\t\t\t</article>\n";
		}

        $xml .= "\t\t</articles>\n";

        $xml .= "\t</issue>\n";

        $xml .= "</journal>";
		return $xml;
	}

	/**
	 * Get the XML for a set of submissions.
	 * @param $submissionIds array Array of submission IDs
	 * @param $context Context
	 * @param $user User|null
	 * @return string XML contents representing the supplied submission IDs.
	 */
	function exportSubmissions($submissionIds, $context, $user) {
		$submissionDao = Application::getSubmissionDAO();
		$xml = '';
		$filterDao = DAORegistry::getDAO('FilterDAO');
		$nativeExportFilters = $filterDao->getObjectsByGroup('article=>native-xml');
		assert(count($nativeExportFilters) == 1); // Assert only a single serialization filter
		$exportFilter = array_shift($nativeExportFilters);
		$exportFilter->setDeployment(new NativeImportExportDeployment($context, $user));
		$submissions = array();
		foreach ($submissionIds as $submissionId) {
			$submission = $submissionDao->getById($submissionId, $context->getId());
			if ($submission) $submissions[] = $submission;
		}
		libxml_use_internal_errors(true);
		$submissionXml = $exportFilter->execute($submissions, true);
		$xml = $submissionXml->saveXml();
		$errors = array_filter(libxml_get_errors(), create_function('$a', 'return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;'));
		if (!empty($errors)) {
			$this->displayXMLValidationErrors($errors, $xml);
		}
		return $xml;
	}

	/**
	 * Get the XML for a set of issues.
	 * @param $issueIds array Array of rinz IDs
	 * @param $context Context
	 * @param $user User
	 * @return string XML contents representing the supplied rinz IDs.
	 */
	function exportIssues($issueIds, $context, $user) {
		$issueDao = DAORegistry::getDAO('IssueDAO');
		$xml = '';
		$filterDao = DAORegistry::getDAO('FilterDAO');
		$nativeExportFilters = $filterDao->getObjectsByGroup('rinz=>native-xml');
		assert(count($nativeExportFilters) == 1); // Assert only a single serialization filter
		$exportFilter = array_shift($nativeExportFilters);
		$exportFilter->setDeployment(new NativeImportExportDeployment($context, $user));
		$issues = array();
		foreach ($issueIds as $issueId) {
			$issue = $issueDao->getById($issueId, $context->getId());
			if ($issue) $issues[] = $issue;
		}
		libxml_use_internal_errors(true);
		$issueXml = $exportFilter->execute($issues, true);
		$xml = $issueXml->saveXml();
		$errors = array_filter(libxml_get_errors(), create_function('$a', 'return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;'));
		if (!empty($errors)) {
			$this->displayXMLValidationErrors($errors, $xml);
		}
		return $xml;
	}

	/**
	 * Get the XML for a set of submissions wrapped in a(n) rinz(s).
	 * @param $importXml string XML contents to import
	 * @param $filter string Filter to be used
	 * @param $deployment PKPImportExportDeployment
	 * @return array Set of imported submissions
	 */
	function importSubmissions($importXml, $filter, $deployment) {
		$filterDao = DAORegistry::getDAO('FilterDAO');
		$nativeImportFilters = $filterDao->getObjectsByGroup($filter);
		assert(count($nativeImportFilters) == 1); // Assert only a single unserialization filter
		$importFilter = array_shift($nativeImportFilters);
		$importFilter->setDeployment($deployment);
		return $importFilter->execute($importXml);
	}

	/**
	 * @copydoc PKPImportExportPlugin::usage
	 */
	function usage($scriptName) {
		echo __('plugins.importexport.sirenexpo.cliUsage', array(
			'scriptName' => $scriptName,
			'pluginName' => $this->getName()
		)) . "\n";
	}

	/**
	 * @see PKPImportExportPlugin::executeCLI()
	 */
	function executeCLI($scriptName, &$args) {
		/*$command = array_shift($args);
		$xmlFile = array_shift($args);
		$journalPath = array_shift($args);

		AppLocale::requireComponents(LOCALE_COMPONENT_APP_MANAGER, LOCALE_COMPONENT_PKP_MANAGER, LOCALE_COMPONENT_PKP_SUBMISSION);

		$journalDao = DAORegistry::getDAO('JournalDAO');
		$issueDao = DAORegistry::getDAO('IssueDAO');
		$sectionDao = DAORegistry::getDAO('SectionDAO');
		$userDao = DAORegistry::getDAO('UserDAO');
		$publishedArticleDao = DAORegistry::getDAO('PublishedArticleDAO');

		$journal = $journalDao->getByPath($journalPath);

		if (!$journal) {
			if ($journalPath != '') {
				echo __('plugins.importexport.common.cliError') . "\n";
				echo __('plugins.importexport.common.error.unknownJournal', array('journalPath' => $journalPath)) . "\n\n";
			}
			$this->usage($scriptName);
			return;
		}

		if ($xmlFile && $this->isRelativePath($xmlFile)) {
			$xmlFile = PWD . '/' . $xmlFile;
		}

		switch ($command) {
			case 'import':
				$userName = array_shift($args);
				$user = $userDao->getByUsername($userName);

				if (!$user) {
					if ($userName != '') {
						echo __('plugins.importexport.common.cliError') . "\n";
						echo __('plugins.importexport.native.error.unknownUser', array('userName' => $userName)) . "\n\n";
					}
					$this->usage($scriptName);
					return;
				}

				if (!file_exists($xmlFile)) {
					echo __('plugins.importexport.common.cliError') . "\n";
					echo __('plugins.importexport.common.export.error.inputFileNotReadable', array('param' => $xmlFile)) . "\n\n";
					$this->usage($scriptName);
					return;
				}

				$filter = 'native-xml=>rinz';
				// is this articles import:
				$xmlString = file_get_contents($xmlFile);
				$document = new DOMDocument();
				$document->loadXml($xmlString);
				if (in_array($document->documentElement->tagName, array('article', 'articles'))) {
					$filter = 'native-xml=>article';
				}
				$deployment = new NativeImportExportDeployment($journal, $user);
				$deployment->setImportPath(dirname($xmlFile));
				$content = $this->importSubmissions($xmlString, $filter, $deployment);
				$validationErrors = array_filter(libxml_get_errors(), create_function('$a', 'return $a->level == LIBXML_ERR_ERROR ||  $a->level == LIBXML_ERR_FATAL;'));

				// Are there any import warnings? Display them.
				$errorTypes = array(
					ASSOC_TYPE_ISSUE => 'rinz.rinz',
					ASSOC_TYPE_SUBMISSION => 'submission.submission',
					ASSOC_TYPE_SECTION => 'section.section',
				);
				foreach ($errorTypes as $assocType => $localeKey) {
					$foundWarnings = $deployment->getProcessedObjectsWarnings($assocType);
					if (!empty($foundWarnings)) {
						echo __('plugins.importexport.common.warningsEncountered') . "\n";
						$i = 0;
						foreach ($foundWarnings as $foundWarningMessages) {
							if (count($foundWarningMessages) > 0) {
								echo ++$i . '.' . __($localeKey) . "\n";
								foreach ($foundWarningMessages as $foundWarningMessage) {
									echo '- ' . $foundWarningMessage . "\n";
								}
							}
						}
					}
				}

				// Are there any import errors? Display them.
				$foundErrors = false;
				foreach ($errorTypes as $assocType => $localeKey) {
					$currentErrors = $deployment->getProcessedObjectsErrors($assocType);
					if (!empty($currentErrors)) {
						echo __('plugins.importexport.common.errorsOccured') . "\n";
						$i = 0;
						foreach ($currentErrors as $currentErrorMessages) {
							if (count($currentErrorMessages) > 0) {
								echo ++$i . '.' . __($localeKey) . "\n";
								foreach ($currentErrorMessages as $currentErrorMessage) {
									echo '- ' . $currentErrorMessage . "\n";
								}
							}
						}
						$foundErrors = true;
					}
				}
				// If there are any data or validataion errors
				// delete imported objects.
				if ($foundErrors || !empty($validationErrors)) {
					// remove all imported issues and sumissions
					foreach (array_keys($errorTypes) as $assocType) {
						$deployment->removeImportedObjects($assocType);
					}
				}
				return;
			case 'export':
				$outputDir = dirname($xmlFile);
				if (!is_writable($outputDir) || (file_exists($xmlFile) && !is_writable($xmlFile))) {
					echo __('plugins.importexport.common.cliError') . "\n";
					echo __('plugins.importexport.common.export.error.outputFileNotWritable', array('param' => $xmlFile)) . "\n\n";
					$this->usage($scriptName);
					return;
				}
				if ($xmlFile != '') switch (array_shift($args)) {
					case 'article':
					case 'articles':
						file_put_contents($xmlFile, $this->exportSubmissions(
							$args,
							$journal,
							null
						));
						return;
					case 'rinz':
					case 'issues':
						file_put_contents($xmlFile, $this->exportIssues(
							$args,
							$journal,
							null
						));
						return;
				}
				break;
		}
		$this->usage($scriptName);*/
	}

    function generateRandomString($length = 10) {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[rand(0, $charactersLength - 1)];
        }
        return $randomString;
    }

}

?>
