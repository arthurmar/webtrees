<?php
namespace Fisharebest\Webtrees;

/**
 * webtrees: online genealogy
 * Copyright (C) 2015 webtrees development team
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

use Zend_Session;

/**
 * Class SearchController - Controller for the search page
 */
class SearchController extends PageController {
	/** @var string The type of search to perform */
	public $action;

	/** @var string "checked" if we are to search individuals, empty otherwise */
	public $srindi;

	/** @var string "checked" if we are to search families, empty otherwise */
	public $srfams;

	/** @var string "checked" if we are to search sources, empty otherwise */
	public $srsour;

	/** @var string "checked" if we are to search notes, empty otherwise */
	public $srnote;

	/** @var Tree[] A list of trees to search */
	public $search_trees = array();

	/** @var Individual[] Individual search results */
	protected $myindilist = array();

	/** @var Source[] Source search results */
	protected $mysourcelist = array();

	/** @var Family[] Family search results */
	protected $myfamlist = array();

	/** @var Note[] Note search results */
	protected $mynotelist = array();

	/** @var string The search term(s) */
	public $query;

	/** @var string The soundex algorithm to use */
	public $soundex;

	// Need to decide if these variables are public/private/protected (or unused)
	var $showasso = 'off';
	var $name;
	var $firstname;
	var $lastname;
	var $place;
	var $year;
	var $replace = '';
	var $replaceNames = false;
	var $replacePlaces = false;
	var $replaceAll = false;
	var $replacePlacesWord = false;

	/**
	 * Startup activity
	 */
	public function __construct() {
		global $WT_TREE;

		parent::__construct();

		// $action comes from GET (search) or POST (replace)
		if (Filter::post('action')) {
			$this->action            = Filter::post('action', 'replace', 'general');
			$this->query             = Filter::post('query');
			$this->replace           = Filter::post('replace');
			$this->replaceNames      = Filter::post('replaceNames', 'checked', '');
			$this->replacePlaces     = Filter::post('replacePlaces', 'checked', '');
			$this->replacePlacesWord = Filter::post('replacePlacesWord', 'checked', '');
			$this->replaceAll        = Filter::post('replaceAll', 'checked', '');
		} else {
			$this->action            = Filter::get('action', 'advanced|general|soundex|replace|header', 'general');
			$this->query             = Filter::get('query');
			$this->replace           = Filter::get('replace');
			$this->replaceNames      = Filter::get('replaceNames', 'checked', '');
			$this->replacePlaces     = Filter::get('replacePlaces', 'checked', '');
			$this->replacePlacesWord = Filter::get('replacePlacesWord', 'checked', '');
			$this->replaceAll        = Filter::get('replaceAll', 'checked', '');
		}

		// Only editors can use search/replace
		if ($this->action === 'replace' && !Auth::isEditor($WT_TREE)) {
			$this->action = 'general';
		}

		$this->srindi            = Filter::get('srindi', 'checked', '');
		$this->srfams            = Filter::get('srfams', 'checked', '');
		$this->srsour            = Filter::get('srsour', 'checked', '');
		$this->srnote            = Filter::get('srnote', 'checked', '');
		$this->soundex           = Filter::get('soundex', 'DaitchM|Russell', 'DaitchM');
		$this->showasso          = Filter::get('showasso');
		$this->firstname         = Filter::get('firstname');
		$this->lastname          = Filter::get('lastname');
		$this->place             = Filter::get('place');
		$this->year              = Filter::get('year');
		$this->name              = Filter::get('name');

		// If no record types specified, search individuals
		if (!$this->srfams && !$this->srsour && !$this->srnote) {
			$this->srindi = 'checked';
		}

		// If no replace types specifiied, replace full records
		if (!$this->replaceNames && !$this->replacePlaces && !$this->replacePlacesWord) {
			$this->replaceAll = 'checked';
		}

		// Trees to search
		if (Site::getPreference('ALLOW_CHANGE_GEDCOM')) {
			foreach (Tree::getAll() as $search_tree) {
				if (Filter::get('tree_' . $search_tree->getTreeId())) {
					$this->search_trees[] = $search_tree;
				}
			}
			if (!$this->search_trees) {
				$this->search_trees[] = $WT_TREE;
			}
		} else {
			$this->search_trees[] = $WT_TREE;
		}

		// If we want to show associated persons, build the list
		switch ($this->action) {
		case 'header':
			// We can type in an XREF into the header search, and jump straight to it.
			// Otherwise, the header search is the same as the general search
			if (preg_match('/' . WT_REGEX_XREF . '/', $this->query)) {
				$record = GedcomRecord::getInstance($this->query, $WT_TREE->getTreeId());
				if ($record && $record->canShowName()) {
					header('Location: ' . WT_BASE_URL . $record->getRawUrl());
					exit;
				}
			}
			$this->action = 'general';
			$this->srindi = 'checked';
			$this->srfams = 'checked';
			$this->srsour = 'checked';
			$this->srnote = 'checked';
			$this->setPageTitle(I18N::translate('General search'));
			$this->generalSearch();
			break;
		case 'general':
			$this->setPageTitle(I18N::translate('General search'));
			$this->generalSearch();
			break;
		case 'soundex':
			// Create a dummy search query to use as a title to the results list
			$this->query = trim($this->firstname . ' ' . $this->lastname . ' ' . $this->place);
			$this->setPageTitle(I18N::translate('Phonetic search'));
			$this->soundexSearch();
			break;
		case 'replace':
			$this->search_trees = array($WT_TREE);
			$this->srindi = 'checked';
			$this->srfams = 'checked';
			$this->srsour = 'checked';
			$this->srnote = 'checked';
			if (Filter::post('query')) {
				$this->searchAndReplace($WT_TREE);
				header('Location: ' . WT_BASE_URL . WT_SCRIPT_NAME . '?action=replace&query=' . Filter::escapeUrl($this->query) . '&replace=' . Filter::escapeUrl($this->replace) . '&replaceAll=' . $this->replaceAll . '&replaceNames=' . $this->replaceNames . '&replacePlaces=' . $this->replacePlaces . '&replacePlacesWord=' . $this->replacePlacesWord);
				exit;
			}
		}
	}

	/**
	 * Gathers results for a general search
	 */
	private function generalSearch() {
		// Split search terms into an array
		$query_terms = array();
		$query       = $this->query;
		// Words in double quotes stay together
		while (preg_match('/"([^"]+)"/', $query, $match)) {
			$query_terms[] = trim($match[1]);
			$query         = str_replace($match[0], '', $query);
		}
		// Other words get treated separately
		while (preg_match('/[\S]+/', $query, $match)) {
			$query_terms[] = trim($match[0]);
			$query         = str_replace($match[0], '', $query);
		}

		//-- perform the search
		if ($query_terms && $this->search_trees) {
			// Write a log entry
			$logstring = "Type: General\nQuery: " . $this->query;
			Log::AddSearchlog($logstring, $this->search_trees);

			// Search the individuals
			if ($this->srindi && $query_terms) {
				$this->myindilist = search_indis($query_terms, $this->search_trees);
			}

			// Search the fams
			if ($this->srfams && $query_terms) {
				$this->myfamlist = array_merge(
					search_fams($query_terms, $this->search_trees),
					search_fams_names($query_terms, $this->search_trees)
				);
				$this->myfamlist = array_unique($this->myfamlist);
			}

			// Search the sources
			if ($this->srsour && $query_terms) {
				$this->mysourcelist = search_sources($query_terms, $this->search_trees);
			}

			// Search the notes
			if ($this->srnote && $query_terms) {
				$this->mynotelist = search_notes($query_terms, $this->search_trees);
			}

			// If only 1 item is returned, automatically forward to that item
			// If ID cannot be displayed, continue to the search page.
			if (count($this->myindilist) == 1 && !$this->myfamlist && !$this->mysourcelist && !$this->mynotelist) {
				$indi = $this->myindilist[0];
				if ($indi->canShowName()) {
					Zend_Session::writeClose();
					header('Location: ' . WT_BASE_URL . $indi->getRawUrl());
					exit;
				}
			}
			if (!$this->myindilist && count($this->myfamlist) == 1 && !$this->mysourcelist && !$this->mynotelist) {
				$fam = $this->myfamlist[0];
				if ($fam->canShowName()) {
					Zend_Session::writeClose();
					header('Location: ' . WT_BASE_URL . $fam->getRawUrl());
					exit;
				}
			}
			if (!$this->myindilist && !$this->myfamlist && count($this->mysourcelist) == 1 && !$this->mynotelist) {
				$sour = $this->mysourcelist[0];
				if ($sour->canShowName()) {
					Zend_Session::writeClose();
					header('Location: ' . WT_BASE_URL . $sour->getRawUrl());
					exit;
				}
			}
			if (!$this->myindilist && !$this->myfamlist && !$this->mysourcelist && count($this->mynotelist) == 1) {
				$note = $this->mynotelist[0];
				if ($note->canShowName()) {
					Zend_Session::writeClose();
					header('Location: ' . WT_BASE_URL . $note->getRawUrl());
					exit;
				}
			}
		}
	}

	/**
	 * Performs a search and replace
	 *
	 * @param Tree $tree
	 */
	private function searchAndReplace(Tree $tree) {
		global $STANDARD_NAME_FACTS;

		$this->generalSearch();

		//-- don't try to make any changes if nothing was found
		if (!$this->myindilist && !$this->myfamlist && !$this->mysourcelist && !$this->mynotelist) {
			return;
		}

		Log::addEditLog("Search And Replace old:" . $this->query . " new:" . $this->replace);

		$adv_name_tags   = preg_split("/[\s,;: ]+/", $tree->getPreference('ADVANCED_NAME_FACTS'));
		$name_tags       = array_unique(array_merge($STANDARD_NAME_FACTS, $adv_name_tags));
		$name_tags[]     = '_MARNM';
		$records_updated = 0;
		foreach ($this->myindilist as $id => $record) {
			$old_record = $record->getGedcom();
			$new_record = $old_record;
			if ($this->replaceAll) {
				$new_record = preg_replace("~" . $this->query . "~i", $this->replace, $new_record);
			} else {
				if ($this->replaceNames) {
					foreach ($name_tags as $tag) {
						$new_record = preg_replace("~(\d) " . $tag . " (.*)" . $this->query . "(.*)~i", "$1 " . $tag . " $2" . $this->replace . "$3", $new_record);
					}
				}
				if ($this->replacePlaces) {
					if ($this->replacePlacesWord) {
						$new_record = preg_replace('~(\d) PLAC (.*)([,\W\s])' . $this->query . '([,\W\s])~i', "$1 PLAC $2$3" . $this->replace . "$4", $new_record);
					} else {
						$new_record = preg_replace("~(\d) PLAC (.*)" . $this->query . "(.*)~i", "$1 PLAC $2" . $this->replace . "$3", $new_record);
					}
				}
			}
			//-- if the record changed replace the record otherwise remove it from the search results
			if ($new_record !== $old_record) {
				$record->updateRecord($new_record, true);
				$records_updated++;
			} else {
				unset($this->myindilist[$id]);
			}
		}

		if ($records_updated) {
			FlashMessages::addMessage(I18N::plural('%s individuals has been updated.', '%s individuals have been updated.', $records_updated, I18N::number($records_updated)));
		}

		$records_updated = 0;
		foreach ($this->myfamlist as $id => $record) {
			$old_record = $record->getGedcom();
			$new_record = $old_record;

			if ($this->replaceAll) {
				$new_record = preg_replace("~" . $this->query . "~i", $this->replace, $new_record);
			} else {
				if ($this->replacePlaces) {
					if ($this->replacePlacesWord) {
						$new_record = preg_replace('~(\d) PLAC (.*)([,\W\s])' . $this->query . '([,\W\s])~i', "$1 PLAC $2$3" . $this->replace . "$4", $new_record);
					} else {
						$new_record = preg_replace("~(\d) PLAC (.*)" . $this->query . "(.*)~i", "$1 PLAC $2" . $this->replace . "$3", $new_record);
					}
				}
			}
			//-- if the record changed replace the record otherwise remove it from the search results
			if ($new_record !== $old_record) {
				$record->updateRecord($new_record, true);
				$records_updated++;
			} else {
				unset($this->myfamlist[$id]);
			}
		}

		if ($records_updated) {
			FlashMessages::addMessage(I18N::plural('%s family has been updated.', '%s families have been updated.', $records_updated, I18N::number($records_updated)));
		}

		$records_updated = 0;
		foreach ($this->mysourcelist as $id => $record) {
			$old_record = $record->getGedcom();
			$new_record = $old_record;

			if ($this->replaceAll) {
				$new_record = preg_replace("~" . $this->query . "~i", $this->replace, $new_record);
			} else {
				if ($this->replaceNames) {
					$new_record = preg_replace("~(\d) TITL (.*)" . $this->query . "(.*)~i", "$1 TITL $2" . $this->replace . "$3", $new_record);
					$new_record = preg_replace("~(\d) ABBR (.*)" . $this->query . "(.*)~i", "$1 ABBR $2" . $this->replace . "$3", $new_record);
				}
				if ($this->replacePlaces) {
					if ($this->replacePlacesWord) {
						$new_record = preg_replace('~(\d) PLAC (.*)([,\W\s])' . $this->query . '([,\W\s])~i', "$1 PLAC $2$3" . $this->replace . "$4", $new_record);
					} else {
						$new_record = preg_replace("~(\d) PLAC (.*)" . $this->query . "(.*)~i", "$1 PLAC $2" . $this->replace . "$3", $new_record);
					}
				}
			}
			//-- if the record changed replace the record otherwise remove it from the search results
			if ($new_record !== $old_record) {
				$record->updateRecord($new_record, true);
				$records_updated++;
			} else {
				unset($this->mysourcelist[$id]);
			}
		}

		if ($records_updated) {
			FlashMessages::addMessage(I18N::plural('%s source has been updated.', '%s sources have been updated.', $records_updated, I18N::number($records_updated)));
		}

		$records_updated = 0;
		foreach ($this->mynotelist as $id => $record) {
			$old_record = $record->getGedcom();
			$new_record = $old_record;

			if ($this->replaceAll) {
				$new_record = preg_replace("~" . $this->query . "~i", $this->replace, $new_record);
			}
			//-- if the record changed replace the record otherwise remove it from the search results
			if ($new_record != $old_record) {
				$record->updateRecord($new_record, true);
				$records_updated++;
			} else {
				unset($this->mynotelist[$id]);
			}
		}

		if ($records_updated) {
			FlashMessages::addMessage(I18N::plural('%s note has been updated.', '%s notes have been updated.', $records_updated, I18N::number($records_updated)));
		}
	}

	/**
	 *  Gathers results for a soundex search
	 *
	 *  NOTE
	 *  ====
	 *  Does not search on the selected gedcoms, searches on all the gedcoms
	 *  Does not work on first names, instead of the code, value array is used in the search
	 *  Returns all the names even when Names with hit selected
	 *  Does not sort results by first name
	 *  Does not work on separate double word surnames
	 *  Does not work on duplicate code values of the searched text and does not give the correct code
	 *     Cohen should give DM codes 556000, 456000, 460000 and 560000, in 4.1 we search only on 560000??
	 *
	 *  The names' Soundex SQL table contains all the soundex values twice
	 *  The places table contains only one value
	 *
	 *  The code should be improved - see RFE
	 *
	 */
	private function soundexSearch() {
		if (((!empty ($this->lastname)) || (!empty ($this->firstname)) || (!empty ($this->place))) && $this->search_trees) {
			$logstring = "Type: Soundex\n";
			if (!empty ($this->lastname)) {
				$logstring .= "Last name: " . $this->lastname . "\n";
			}
			if (!empty ($this->firstname)) {
				$logstring .= "First name: " . $this->firstname . "\n";
			}
			if (!empty ($this->place)) {
				$logstring .= "Place: " . $this->place . "\n";
			}
			if (!empty ($this->year)) {
				$logstring .= "Year: " . $this->year . "\n";
			}
			Log::addSearchLog($logstring, $this->search_trees);

			if ($this->search_trees) {
				$this->myindilist = search_indis_soundex($this->soundex, $this->lastname, $this->firstname, $this->place, $this->search_trees);
			} else {
				$this->myindilist = array();
			}
		}

		// Now we have the final list of individuals to be printed.
		// We may add the assos at this point.

		if ($this->showasso == 'on') {
			foreach ($this->myindilist as $indi) {
				foreach ($indi->linkedIndividuals('ASSO') as $asso) {
					$this->myindilist[] = $asso;
				}
				foreach ($indi->linkedIndividuals('_ASSO') as $asso) {
					$this->myindilist[] = $asso;
				}
				foreach ($indi->linkedFamilies('ASSO') as $asso) {
					$this->myfamlist[] = $asso;
				}
				foreach ($indi->linkedFamilies('_ASSO') as $asso) {
					$this->myfamlist[] = $asso;
				}
			}
		}

		//-- if only 1 item is returned, automatically forward to that item
		if (count($this->myindilist) == 1 && $this->action != "replace") {
			$indi = $this->myindilist[0];
			header('Location: ' . WT_BASE_URL . $indi->getRawUrl());
			exit;
		}
		usort($this->myindilist, __NAMESPACE__ . '\GedcomRecord::compare');
		usort($this->myfamlist, __NAMESPACE__ . '\GedcomRecord::compare');
	}

	/**
	 * @return bool
	 */
	function printResults() {
		if ($this->action !== 'replace' && ($this->query || $this->firstname || $this->lastname || $this->place)) {
			if ($this->myindilist || $this->myfamlist || $this->mysourcelist || $this->mynotelist) {
				$this->addInlineJavascript('jQuery("#search-result-tabs").tabs();');
				$this->addInlineJavascript('jQuery("#search-result-tabs").css("visibility", "visible");');
				$this->addInlineJavascript('jQuery(".loading-image").css("display", "none");');
				echo '<br>';
				echo '<div class="loading-image"></div>';
				echo '<div id="search-result-tabs"><ul>';
				if ($this->myindilist) {
					echo '<li><a href="#searchAccordion-indi"><span id="indisource">', I18N::translate('Individuals'), '</span></a></li>';
				}
				if ($this->myfamlist) {
					echo '<li><a href="#searchAccordion-fam"><span id="famsource">', I18N::translate('Families'), '</span></a></li>';
				}
				if ($this->mysourcelist) {
					echo '<li><a href="#searchAccordion-source"><span id="mediasource">', I18N::translate('Sources'), '</span></a></li>';
				}
				if ($this->mynotelist) {
					echo '<li><a href="#searchAccordion-note"><span id="notesource">', I18N::translate('Notes'), '</span></a></li>';
				}
				echo '</ul>';

				// individual results
				echo '<div id="searchAccordion-indi">';
				// Split individuals by tree
				foreach ($this->search_trees as $search_tree) {
					$datalist = array();
					foreach ($this->myindilist as $individual) {
						if ($individual->getTree()->getTreeId() === $search_tree->getTreeId()) {
							$datalist[] = $individual;
						}
					}
					if ($datalist) {
						usort($datalist, __NAMESPACE__ . '\GedcomRecord::compare');
						echo '<h3 class="indi-acc-header"><a href="#"><span class="search_item" dir="auto">', Filter::escapeHtml($this->query), '</span> @ <span>', $search_tree->getTitleHtml(), '</span></a></h3>
							<div class="indi-acc_content">',
						format_indi_table($datalist);
						echo '</div>'; //indi-acc_content
					}
				}
				echo '</div>';
				$this->addInlineJavascript('jQuery("#searchAccordion-indi").accordion({heightStyle: "content", collapsible: true});');

				// family results
				echo '<div id="searchAccordion-fam">';
				// Split families by gedcom
				foreach ($this->search_trees as $search_tree) {
					$datalist = array();
					foreach ($this->myfamlist as $family) {
						if ($family->getTree()->getTreeId() === $search_tree->getTreeId()) {
							$datalist[] = $family;
						}
					}
					if ($datalist) {
						usort($datalist, __NAMESPACE__ . '\GedcomRecord::compare');
						echo '<h3 class="fam-acc-header"><a href="#"><span class="search_item" dir="auto">', Filter::escapeHtml($this->query), '</span> @ <span>', $search_tree->getTitleHtml(), '</span></a></h3>
							<div class="fam-acc_content">',
						format_fam_table($datalist);
						echo '</div>'; //fam-acc_content
					}
				}
				echo '</div>'; //#searchAccordion-fam
				$this->addInlineJavascript('jQuery("#searchAccordion-fam").accordion({heightStyle: "content", collapsible: true});');
				// source results
				echo '<div id="searchAccordion-source">';
				// Split sources by gedcom
				foreach ($this->search_trees as $search_tree) {
					$datalist = array();
					foreach ($this->mysourcelist as $source) {
						if ($source->getTree()->getTreeId() === $search_tree->getTreeId()) {
							$datalist[] = $source;
						}
					}
					if ($datalist) {
						usort($datalist, __NAMESPACE__ . '\GedcomRecord::compare');
						echo '<h3 class="source-acc-header"><a href="#"><span class="search_item" dir="auto">', Filter::escapeHtml($this->query), '</span> @ <span>', $search_tree->getTitleHtml(), '</span></a></h3>
							<div class="source-acc_content">',
						format_sour_table($datalist);
						echo '</div>'; //fam-acc_content
					}
				}
				echo '</div>'; //#searchAccordion-source
				$this->addInlineJavascript('jQuery("#searchAccordion-source").accordion({heightStyle: "content", collapsible: true});');
				// note results
				echo '<div id="searchAccordion-note">';
				// Split notes by gedcom
				foreach ($this->search_trees as $search_tree) {
					$datalist = array();
					foreach ($this->mynotelist as $note) {
						if ($note->getTree()->getTreeId() === $search_tree->getTreeId()) {
							$datalist[] = $note;
						}
					}
					if ($datalist) {
						usort($datalist, __NAMESPACE__ . '\GedcomRecord::compare');
						usort($datalist, 'Webtrees\GedcomRecord::compare');
						echo '<h3 class="note-acc-header"><a href="#"><span class="search_item" dir="auto">', Filter::escapeHtml($this->query), '</span> @ <span>', $search_tree->getTitleHtml(), '</span></a></h3>
							<div class="note-acc_content">',
						format_note_table($datalist);
						echo '</div>'; //note-acc_content
					}
				}
				echo '</div>'; //#searchAccordion-note
				$this->addInlineJavascript('jQuery("#searchAccordion-note").accordion({heightStyle: "content", collapsible: true});');
				echo '</div>'; //#search-result-tabs
			} else {
				// One or more search terms were specified, but no results were found.
				echo '<div class="warning center">' . I18N::translate('No results found.') . '</div>';
			}
		}
	}
}
