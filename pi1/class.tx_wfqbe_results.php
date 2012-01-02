<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2006 Davide Menegon <menedav@libero.it>
*  (c) 2007 Mauro Lorenzutti <mauro.lorenzutti@webformat.com>
*  All rights reserved
*
*  This script is part of the TYPO3 project. The TYPO3 project is
*  free software; you can redistribute it and/or modify
*  it under the terms of the GNU General Public License as published by
*  the Free Software Foundation; either version 2 of the License, or
*  (at your option) any later version.
*
*  The GNU General Public License can be found at
*  http://www.gnu.org/copyleft/gpl.html.
*
*  This script is distributed in the hope that it will be useful,
*  but WITHOUT ANY WARRANTY; without even the implied warranty of
*  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
*  GNU General Public License for more details.
*
*  This copyright notice MUST APPEAR in all copies of the script!
***************************************************************/
/**
 * Search results class for the 'wfqbe' extension.
 *
 * @author	Davide Menegon <menedav@libero.it>
 * @author	Mauro Lorenzutti <mauro.lorenzutti@webformat.com>
 *
 */


class tx_wfqbe_results {

	var $conf;
	var $cObj;
	var $pibase;
	var $query;
	var $executionTime;

	function main($conf, $cObj, $pibase)	{
		$this->conf=$conf;
		$this->cObj = $cObj;
		$this->pibase = $pibase;
	}




	/**
	 * This function is used to show the results
	 */
	function do_sGetFormResult($row, $h)	{
		$ris = $this->getResultQuery($row, $h);
		$mA = array();

		if ($this->conf['debugQuery'])
			$content .= '<br /><strong>Query constructed:</strong><br />'.$this->query.'<br /><strong>Execution time:</strong>'.$this->executionTime.'<br /><br />';

		if($ris === false)	{
			$content .= "Query failed (uid=".$row['uid'].")<br />".$h->ErrorMsg();
			return $content;
		}

		if ($ris->RecordCount()==0)	{
			// if the resultset is empty, it shows the empty results template
			$content .= $this->emptyLayout($row);
		}	else	{
			// This checks if the user has set a template. If yes it uses the template set, else it uses the default one
			if($this->conf["defLayout"]==0 || $this->conf["defLayout"]=="" || t3lib_div::_GP('type')==181)
				$content.=$this->defaultLayout($ris,$row);
			else
				$content.=$this->userLayout($ris,$row);

			if ($this->conf['exportAll']==1)
				$this->pibase->piVars['showpage'] = 'all';

			if ($this->conf['customProcess.'][$row['uid'].'.']['CSVquery']!='')
				$csv_query = $this->conf['customProcess.'][$row['uid'].'.']['CSVquery'];
			if ($this->conf['customGlobalProcess.']['CSVquery']!='')
				$csv_query = $this->conf['customGlobalProcess.']['CSVquery'];
			else
				$csv_query = $row['uid'];
			$mA["###CONF_CSV###"] = htmlentities($this->pibase->pi_linkTP_keepPIvars_url().'&type=181&tx_wfqbe_pi1[wfqbe_results_query]='.$csv_query);
			$mA['###LABEL_CSV###'] = $this->pibase->pi_getLL('csv_link', 'Export in CSV');
			
			$mA['###CONF_DIVID###'] = $this->conf['ff_data']['div_id'];
		}

		$content = $this->cObj->substituteMarkerArray($content, $mA);
		return $content;
	}




	function getResultQuery($row, $h)	{
		// SELECT
		$API = t3lib_div::makeInstance("tx_wfqbe_api_xml2array");
		$API2 = t3lib_div::makeInstance("tx_wfqbe_queryform_generator");
		$loadValue= $API->xml2array($row["query"]);//converto la stringa che rappresenta la query creata tramite wizard in array
		$wfqbe=$loadValue['contentwfqbe']["wfqbe"];//selezione solo una parte e cio� elimino in tag radice <wfqbe>
		$rawwfqbe=$loadValue['contentwfqbe']["rawwfqbe"];
		$query= $API2->createQuery($wfqbe,$rawwfqbe,$this->pibase->piVars,$row['uid']);//This function creates the SQL query
		$mA = array();

		// Gestione parametri query
		// Sosituisce i marcatori ###WFQBE_NOMEVARIABILE### con t3lib_div::_GP('wfqbe[nomevariabile]')
		$parametri = $this->pibase->piVars;
		$markerParametri = array();
//t3lib_div::debug($parametri);
		if (is_array($parametri))	{
			foreach ($parametri as $key => $value)	{
				if (!is_array($value))	{
					$markerParametri["###WFQBE_".strtoupper($key)."###"] = addslashes(strip_tags($value));
				}	elseif (sizeof($value)==1)	{
					$markerParametri["###WFQBE_".strtoupper($key)."###"] = addslashes(strip_tags($value[0]));
				}	else	{
					$i=0;
					foreach ($value as $sel)	{
						if ($i>0)
							$markerParametri["###WFQBE_".strtoupper($key)."###"] .= "'";
						$markerParametri["###WFQBE_".strtoupper($key)."###"] .= addslashes(strip_tags($sel));
						if ($i<sizeof($value)-1)
							$markerParametri["###WFQBE_".strtoupper($key)."###"] .= "',";
						$i++;
					}
				}
			}
			/*
			// Hook that can be used to pre-process a parameter (from a search form) before makeing the query
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['wfqbe']['processSubstituteSearchParametersClass']))    {
			    foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['wfqbe']['processSubstituteSearchParametersClass'] as $_classRef)    {
			        $_procObj = &t3lib_div::getUserObj($_classRef);
			        $markerParametri = $_procObj->parse_search_markers($markerParametri, $parametri, $this);
			    }
			}

			$query = $this->cObj->substituteMarkerArray($query, $markerParametri); */
		}
		//unset($markerParametri);

		// This is used to parse the query and to retrieve the TS markers (like ###TS_WFQBE_xxx###) and non-substituted markers (like ###WFQBE_xxx###)
		// This markers are replaced with the output of TS objects defined in your TS template
		$tsMarkers = $this->getTSMarkers($query);
		if (is_array($tsMarkers))	{
			foreach ($tsMarkers as $marker)	{
				$emptyCase = false;

				if ($this->conf['customQuery.'][$row['uid'].'.'][$marker]!="" && (($markerParametri["###".$marker."###"]=='' && $this->conf['customQuery.'][$row['uid'].'.'][$marker."."]["overrideIfEmpty"]==1) || $this->conf['customQuery.'][$row['uid'].'.'][$marker."."]["overrideAlways"]==1))	{
					if ($markerParametri["###".$marker."###"]=='' && $this->conf['customQuery.'][$row['uid'].'.'][$marker."."]["overrideIfEmpty"]==1)
						$emptyCase = true;
					$confArray = $this->conf["customQuery."][$row['uid']."."][$marker."."];
					//$confArray = $this->parseTypoScriptConfiguration($confArray, $wfqbeArray);
					eval('$markerParametri["###".$marker."###"]=$this->cObj->'.$this->conf["customQuery."][$row["uid"]."."][$marker].'($confArray);');
				}	elseif ($this->conf['globalCustomQuery.'][$marker]) {
					$confArray = $this->conf["globalCustomQuery."][$marker."."];
					eval('$markerParametri["###".$marker."###"]=$this->cObj->'.$this->conf["globalCustomQuery."][$marker].'($confArray);');
				}

				if (!$emptyCase && $this->conf['customQuery.'][$row['uid'].'.'][$marker.'.']!="" && $this->conf['customQuery.'][$row['uid'].'.'][$marker."."]["wfqbe."]['intval']==1)	{
					$markerParametri["###".$marker."###"] = intval($markerParametri["###".$marker."###"]);
				}	elseif (!$emptyCase && $this->conf['customQuery.'][$row['uid'].'.'][$marker.'.']!="" && $this->conf['customQuery.'][$row['uid'].'.'][$marker."."]["wfqbe."]['floatval']==1)	{
					$markerParametri["###".$marker."###"] = floatval($markerParametri["###".$marker."###"]);
				}
			}
			//$query = $this->cObj->substituteMarkerArray($query, $markerParametri);
		}

		if (sizeof($markerParametri)>0)	{
			// Hook that can be used to pre-process a parameter (from a search form) before makeing the query
			if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['wfqbe']['processSubstituteSearchParametersClass']))    {
			    foreach($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['wfqbe']['processSubstituteSearchParametersClass'] as $_classRef)    {
			        $_procObj = &t3lib_div::getUserObj($_classRef);
			        $markerParametri = $_procObj->parse_search_markers($markerParametri, $parametri, $this);
			    }
			}
			$query = $this->cObj->substituteMarkerArray($query, $markerParametri);
		}

		unset($markerParametri);

		$this->query = preg_replace("/(###)+[a-z,A-Z,0-9,@,!,_]+(###)/","",$query);
		
		$start = microtime(true);
		$ris = $h -> Execute($this->query);//eseguo la query definita dall'utente e cio� quello che voglio visualizzare a frontend
		$stop = microtime(true);
		$this->executionTime = ($stop - $start).' sec';

		return $ris;
	}




	/**
	 * This function is used to show the empty results template
	 */
	function emptyLayout($row)	{
		$file = $this->cObj->fileResource($this->conf['template']);
		$emptyTemplate = trim($this->cObj->getSubpart($file,"EMPTY_RESULT_TEMPLATE"));

		foreach ($row as $key => $value)
			$mA['###TABLE_'.strtoupper($key).'###'] = $value;
		if ($this->conf['ff_data']['emptyResult']!='')
			$mA['###CONF_EMPTYTEXT###'] = $this->conf['ff_data']['emptyResult'];
		else
			$mA['###CONF_EMPTYTEXT###'] = 'No data';

		if ($this->pibase->piVars['wfqbe_select_wizard']=='')	{
			$emptyTemplate = $this->cObj->substituteSubpart($emptyTemplate, '###WIZARD_TEMPLATE###', '', 0,0);
		}	else	{
			$params = array();
			$params['parameter'] = $GLOBALS['TSFE']->id;
			$action = $this->cObj->typoLink_URL($params);
			$mA['###CONF_INSERT###'] = $this->cObj->typoLink_URL($params);
			$API = t3lib_div::makeInstance("tx_wfqbe_utils");
			$mA['###INSERT_HIDDEN_FIELDS###'] = $API->getHiddenFields($this->pibase->piVars);
		}
		$mA['###CONF_DIVID###'] = $this->conf['ff_data']['div_id'];
		$mA['###LABEL_CANCEL###'] = $this->pibase->pi_getLL('cancel', 'Cancel');

		$content = $this->cObj->substituteMarkerArray($emptyTemplate, $mA);
		return $content;
	}



	/**
	 * This function is used to show the default results template
	 */
	function defaultLayout($ris,$row){
		$listaTabelle='';//dovr� contenere un elenco di template di varie tabelle
		$file = $this->cObj->fileResource($this->conf['template']);

		if ($this->pibase->piVars['wfqbe_select_wizard']!='')	{
			$templateTabella = trim($this->cObj->getSubpart($file,"SELECT_WIZARD_TEMPLATE"));
		}	else	{
			$templateTabella = trim($this->cObj->getSubpart($file,"RESULT_TEMPLATE"));
		}

		//le seguenti righe servono per estrarre delle sotto parti del template della tabella che utilizzo pi� avanti per
		//effettuare delle sostituzioni(parti di template con i risultati dell'interrogazione) e per definire delle variabili
		//che utilizzo per inserire varie parti della tabella risultante
		$listaRighe="";
		$listaIntestazione="";

		$templateIntestazione = trim($this->cObj->getSubpart($templateTabella,"TH_TAG"));
		$templateColonne = trim($this->cObj->getSubpart($templateTabella,"TD_TAG"));
		$templateRighe = trim($this->cObj->getSubpart($templateTabella,"TABLE_DATA"));

		$numRows = $ris->RecordCount();
		$this->conf['wf_data']['queryNumRows'] = $numRows;
		
		$actualPage = $this->pibase->piVars['showpage'][$row['uid']]!="" ? $this->pibase->piVars['showpage'][$row['uid']] : 1;
		if ($this->conf['ff_data']['recordsForPage']=='' || $this->conf['ff_data']['recordsForPage']==0 || $actualPage=='all')
			$this->conf['ff_data']['recordsForPage']=$numRows;
		$numPages = $numRows==0 ? $numRows : ceil($numRows / $this->conf['ff_data']['recordsForPage']);

		if ($this->pibase->piVars['wfqbe_select_wizard']!='')	{
			$this->conf['ff_data']['recordsForPage'] = $numRows;
			// Inits the array of selected values
			if (!is_array($this->pibase->piVars[$this->pibase->piVars['wfqbe_select_wizard']]))
				$selected_values = explode(",", $this->pibase->piVars[$this->pibase->piVars['wfqbe_select_wizard']]);
			else
				$selected_values = $this->pibase->piVars[$this->pibase->piVars['wfqbe_select_wizard']];
		}


		for ($i=0; $i<($actualPage-1)*$this->conf['ff_data']['recordsForPage']; $i++)
			$ris->FetchRow();

		$flag=0;
		$colspan = 0;
		while($flag<$this->conf['ff_data']['recordsForPage'] && $array = $ris -> FetchRow()) {
			if($flag==0)	{
				$keys = array_keys($array);
			}
			$wfqbeArray = array();
			$mA = array();
			foreach ($array as $key => $value)
				$wfqbeArray['###WFQBE_FIELD_'.$key.'###'] = $value;
			$listaColonne="";
			for($i=0;$i<sizeof($array);$i++){
				if (is_int($keys[$i]) && !t3lib_div::inList($this->conf["customProcess."][$row['uid']."."]['excludeColumns'], $keys[$i]))	{
					$mA["###TD_ATTRIBUTES###"] = $this->conf['globalCustomProcess.'][$keys[$i].'.']["td_attribs"]!='' ? $this->conf['globalCustomProcess.'][$keys[$i].'.']["td_attribs"] : $this->conf["customProcess."][$row['uid']."."][$keys[$i]."."]["td_attribs"];
					if (
							(
								($this->conf["globalCustomProcess."]['excludeDuplicatedValuesInColumns']!='' && t3lib_div::inList($this->conf["globalCustomProcess."]['excludeDuplicatedValuesInColumns'], $keys[$i]))
								 ||
								 ($this->conf["customProcess."][$row['uid']."."]['excludeDuplicatedValuesInColumns']!='' && t3lib_div::inList($this->conf["customProcess."][$row['uid']."."]['excludeDuplicatedValuesInColumns'], $keys[$i])
								 )
								 ) && $array[$keys[$i]]==$excludeDuplicatedValuesInColumns[$keys[$i]]){
						$mA["###COLUMN_DATA###"]='';
						$mA["###TD_ATTRIBUTES###"] = $this->conf['globalCustomProcess.'][$keys[$i].'.']["td_attribs_emptyDuplicate"]!='' ? $this->conf['globalCustomProcess.'][$keys[$i].'.']["td_attribs_emptyDuplicate"] : $this->conf["customProcess."][$row['uid']."."][$keys[$i]."."]["td_attribs_emptyDuplicate"];
					}	else	{
						$excludeDuplicatedValuesInColumns[$keys[$i]] = $array[$keys[$i]];
						if (
							(
								($this->conf["globalCustomProcess."]['excludeDuplicatedValuesInColumns']!='' && t3lib_div::inList($this->conf["globalCustomProcess."]['excludeDuplicatedValuesInColumns'], $keys[$i]))
								 ||
								 ($this->conf["customProcess."][$row['uid']."."]['excludeDuplicatedValuesInColumns']!='' && t3lib_div::inList($this->conf["customProcess."][$row['uid']."."]['excludeDuplicatedValuesInColumns'], $keys[$i])
								 )
								 ))
							$mA["###TD_ATTRIBUTES###"] = $this->conf['globalCustomProcess.'][$keys[$i].'.']["td_attribs_valueDuplicate"]!='' ? $this->conf['globalCustomProcess.'][$keys[$i].'.']["td_attribs_valueDuplicate"] : $this->conf["customProcess."][$row['uid']."."][$keys[$i]."."]["td_attribs_valueDuplicate"];
							
						if ($this->conf["customProcess."][$row['uid']."."][$keys[$i]]!="")	{
							$mergedConf = $this->manageTyposcriptAlternatives($flag, 'customProcess', $row['uid'], $keys[$i]);
							$confArray = $mergedConf['config'];
							$confFunc = $mergedConf['func'];
							$confArray = $this->parseTypoScriptConfiguration($confArray, $wfqbeArray);
							eval('$mA["###COLUMN_DATA###"]=$this->cObj->'.$confFunc.'($confArray);');
						}	elseif ($this->conf['globalCustomProcess.'][$keys[$i]]) {
							$mergedConf = $this->manageTyposcriptAlternatives($flag, 'customProcess', $row['uid'], $keys[$i]);
							$confArray = $mergedConf['config'];
							$confFunc = $mergedConf['func'];
							$confArray = $this->parseTypoScriptConfiguration($confArray, $wfqbeArray);
							eval('$mA["###COLUMN_DATA###"]=$this->cObj->'.$confFunc.'($confArray);');
						}	else	{
							$mA["###COLUMN_DATA###"]=$array[$keys[$i]];
						}
					}
					if (is_array($this->pibase->insertBlocks))	{
						$mA['###INSERT_FIELD###'] = $array[$this->pibase->insertBlocks['fields'][$this->pibase->piVars['wfqbe_select_wizard']]['form']['field_insert']];
						if (is_array($selected_values) && in_array($array[$this->pibase->insertBlocks['fields'][$this->pibase->piVars['wfqbe_select_wizard']]['form']['field_insert']], $selected_values))	{
							$mA['###WFQBE_SELECT_WIZARD_SELECTED###'] = ' checked="checked"';
						}	else	{
							$mA['###WFQBE_SELECT_WIZARD_SELECTED###'] = '';
						}
					}
					$mA["###COLUMN_NAME_I###"] = "###COLUMN_NAME_".($i/2)."###";

					$listaColonne.=$this->cObj->substituteMarkerArray($templateColonne, $mA);
					if ($flag==0)
						$colspan++;
				}
			}

			if ($this->conf['export_mode']=='csv' && t3lib_div::_GP('type')=="181")
				$listaColonne .= "\n\r";

			$flag++;
			//il seguente codice(if/else) serve per settare la classe della riga per poter poi dare un layout diverso a frontend.
			//se la riga � pari si setta la classe pari altrimanti la classe dispari.Queste due classi hanno un colore di background
			//diverso
			if($flag%2==0)
				$mA["###WFQBE_CLASS###"]=$this->conf['classes.']['even'];
			else
				$mA["###WFQBE_CLASS###"]=$this->conf['classes.']['odd'];


			//in templateRighe sostituisco la sezione TD_TAG con la lista delle colonne(che rappresenta la riga appena creata)
			//e metto l'html creato nella variabile rigaAtt
			$rigaAtt = $this->cObj->substituteSubpart($templateRighe,"###TD_TAG###",$listaColonne,$recursive=0,$keepMarker=0);
			//setto in rigaAtt (tramite mA5) il valore della classe e accodo la riga atttuale nella lista righe
			$listaRighe.=$this->cObj->substituteMarkerArray($rigaAtt, $mA);
		}

		//scandisco l'array che contiene le chiavi e le accodo nella variabile $listaIntestazione
		$hA = array();
		for($i=0;$i<$ris->FieldCount();$i++){
			if (t3lib_div::inList($this->conf["customProcess."][$row['uid']."."]['excludeColumns'], $i))
				continue;

			if ($this->conf['results.']['enableOrderByHeaders']==1)
				$enableOrderByHeaders = 1;
			else
				$enableOrderByHeaders = 0;

			//siccome $key identifica una posizione con l'indice o in modo associativo utilizzo la funzione is_int() per non visualizzare
			//l'intestazione in forma numerica(ha pi� senso visualizzare quella associativa che � il nome della colonna della tabella).
			$field = $ris->FetchField($i);
			if ($this->conf["customHeader."][$row['uid']."."][$i]!="")	{
				$wfqbeArray = array();
				$wfqbeArray['###WFQBE_NAME_'.$key.'###'] = $field->name;
				$confArray = $this->conf["customHeader."][$row['uid']."."][$i."."];
				$confArray = $this->parseTypoScriptConfiguration($confArray, $wfqbeArray);
				eval('$mA["###COLUMN_NAME###"]=$this->cObj->'.$this->conf["customHeader."][$row["uid"]."."][$i].'($confArray);');
				if ($confArray['enableOrderByHeaders']==1)
					$enableOrderByHeaders = 1;
				elseif ($confArray['enableOrderByHeaders']==0 && t3lib_div::testInt($confArray['enableOrderByHeaders']))
					$enableOrderByHeaders = 0;
			}	elseif ($this->conf['globalCustomProcess.'][$marker]) {
				$wfqbeArray = array();
				$wfqbeArray['###WFQBE_NAME_'.$key.'###'] = $field->name;
				$confArray = $this->conf["globalCustomHeader."][$i."."];
				$confArray = $this->parseTypoScriptConfiguration($confArray, $wfqbeArray);
				eval('$mA["###COLUMN_NAME###"]=$this->cObj->'.$this->conf["globalCustomHeader."][$i].'($confArray);');
				if ($confArray['enableOrderByHeaders']==1)
					$enableOrderByHeaders = 1;
				elseif ($confArray['enableOrderByHeaders']==0 && t3lib_div::testInt($confArray['enableOrderByHeaders']))
					$enableOrderByHeaders = 0;
			}	else	{
				$mA["###COLUMN_NAME###"]= $field->name;
			}

			$hA["###COLUMN_NAME_".$i."###"] = $mA["###COLUMN_NAME###"];

			if ($enableOrderByHeaders && t3lib_div::_GP('type')!=181)	{
				$mode = 'ASC';
				if ($this->pibase->piVars['orderby']['field']==$field->name && $this->pibase->piVars['orderby']['mode']=='ASC')
					$mode = 'DESC';
				$orderLink = array();
				$orderLink['parameter'] = $GLOBALS['TSFE']->id;
				$orderLink['addQueryString'] = 1;
				$orderLink['addQueryString.']['method'] = 'POST,GET';
				$orderLink['addQueryString.']['exclude'] = 'id';
				$orderLink['additionalParams'] = '&tx_wfqbe_pi1[orderby][mode]='.$mode.'&tx_wfqbe_pi1[orderby][field]='.$field->name;
				$mA["###COLUMN_NAME###"] = $this->cObj->typolink($mA["###COLUMN_NAME###"], $orderLink);
			}

			$listaIntestazione.=$this->cObj->substituteMarkerArray($templateIntestazione, $mA);
		}

		$mA = array();

		if ($this->pibase->piVars['wfqbe_select_wizard']!='')	{
			// This section is used to configure the visualization if in "select wizard" mode
			$mA['###WFQBE_DESCRIPTION###'] = $row['description'];
			$mA['###WFQBE_SELECT_WIZARD###'] = $this->pibase->piVars['wfqbe_select_wizard'];
			$mA['###WFQBE_SELECT_WIZARD_TYPE###'] = $this->pibase->piVars['wfqbe_select_wizard_type'];
			$API = t3lib_div::makeInstance("tx_wfqbe_utils");
			$mA['###INSERT_HIDDEN_FIELDS###'] = $API->getHiddenFields($this->pibase->piVars, '', $this->pibase->piVars['wfqbe_select_wizard']);

			$params = array();
			$params['parameter'] = $GLOBALS['TSFE']->id;
			$mA['###CONF_INSERT###'] = $this->cObj->typoLink_URL($params);

			// Adds an empty selection
			$mA['###COLUMN_COLSPAN###'] = $colspan;
			$flag++;
			if($flag%2==0)
				$mA["###WFQBE_CLASS###"]=$this->conf['classes.']['even'];
			else
				$mA["###WFQBE_CLASS###"]=$this->conf['classes.']['odd'];
		}
		if ($this->pibase->piVars['wfqbe_select_wizard_type']!='radio')	{
			$templateTabella = $this->cObj->substituteSubpart($templateTabella, '###TABLE_NOVALUE###', '', 0,0);
		}

		//sostituisco nella templateTabellaAtt(che contiene il template della tabella e cio� quello che deve venire visualizzato)
		//la sezione 'TH_TAG' con le intestazioni della tabella e la sezione 'TABLE_DATA' con le righe che contengono il
		//risultato vero e proprio della interrogazione.
		$content = $this->cObj->substituteSubpart($templateTabella,"###TH_TAG###",$listaIntestazione,$recursive=0,$keepMarker=0);
		$content = $this->cObj->substituteSubpart($content,"###TABLE_DATA###",$listaRighe,$recursive=0,$keepMarker=0);

		//le seguenti 4 righe servono per creare dinamicamente il tag caption e l'attributo summary necessari per rendere accessibile la tabella
		//risultante.Si crea una variabile(mA3 per caption e mA4 per summary) che contiente i valori inseriti dall'utente e ricavati tramite la query iniziale
		//e la si sostituisce al templateTabellaAtt
		$mA["###TABLE_CAPTION###"]=$this->conf['ff_data']['caption'];
		$mA["###TABLE_SUMMARY###"]=$this->conf['ff_data']['summary'];
		
		$mA['###WFQBE_NUMROWS###'] = $numRows;

		if ($numPages<2)
			$content = $this->cObj->substituteSubpart($content, '###BROWSE_TEMPLATE###', '', 1,0);
		else	{
			$this->showBrowser($mA, $numPages, $numRows, $actualPage, $row['uid']);
		}

		if ($this->conf['ff_data']['csvDownload']!=1)
			$content = $this->cObj->substituteSubpart($content, '###CSV_DOWNLOAD###', '', 0,0);

		$mA['###LABEL_CANCEL###'] = $this->pibase->pi_getLL('cancel', 'Cancel');
		$mA['###LABEL_INSERT###'] = $this->pibase->pi_getLL('insert_submit', 'Insert');
		$mA['###LABEL_SELECT###'] = $this->pibase->pi_getLL('select', 'Select');
		$mA['###LABEL_NO_VALUE###'] = $this->pibase->pi_getLL('no_value', 'No value');

		$content =$this->cObj->substituteMarkerArray($content, $mA);
		$content =$this->cObj->substituteMarkerArray($content, $hA);

		return $content;
    }




	/**
	 * This function is used to show the custom results template
	 */
	function userLayout($ris,$row){
		$file = $this->cObj->fileResource($this->conf["template"]);
		$template = trim($this->cObj->getSubpart($file,"RESULT_TEMPLATE"));;
		$templateLista = trim($this->cObj->getSubpart($template,"DATA_TEMPLATE"));
		$listaRighe="";

		$numRows = $ris->RecordCount();
		$this->conf['wf_data']['queryNumRows'] = $numRows;

		$actualPage = $this->pibase->piVars['showpage'][$row['uid']]!="" ? $this->pibase->piVars['showpage'][$row['uid']] : 1;
		if ($this->conf['ff_data']['recordsForPage']=='' || $this->conf['ff_data']['recordsForPage']==0 || $actualPage=='all')
			$this->conf['ff_data']['recordsForPage']=$numRows;
			
		$numPages = ceil($numRows / $this->conf['ff_data']['recordsForPage']);

		for ($i=0; $i<($actualPage-1)*$this->conf['ff_data']['recordsForPage']; $i++)
			$ris->FetchRow();

		$flag=0;
		while($flag<$this->conf['ff_data']['recordsForPage'] && $array = $ris -> FetchRow()){
			if ($flag==0)
				$keys = array_keys($array);
			
			$wfqbeArray = array();
			$mA = array();
			foreach ($array as $key => $value)
				$wfqbeArray['###WFQBE_FIELD_'.$key.'###'] = $value;

			for($i=0;$i<sizeof($array);$i++){
				if (
							(
								($this->conf["globalCustomProcess."]['excludeDuplicatedValuesInColumns']!='' && t3lib_div::inList($this->conf["globalCustomProcess."]['excludeDuplicatedValuesInColumns'], $keys[$i]))
								 ||
								 ($this->conf["customProcess."][$row['uid']."."]['excludeDuplicatedValuesInColumns']!='' && t3lib_div::inList($this->conf["customProcess."][$row['uid']."."]['excludeDuplicatedValuesInColumns'], $keys[$i])
								 )
								 ) && $array[$keys[$i]]==$excludeDuplicatedValuesInColumns[$keys[$i]]){
						$mA["###FIELD_".$keys[$i]."###"]='';
					}	else	{
						$excludeDuplicatedValuesInColumns[$keys[$i]] = $array[$keys[$i]];


					if ($this->conf["customProcess."][$row['uid']."."][$keys[$i]]!="")	{
						$mergedConf = $this->manageTyposcriptAlternatives($flag, 'customProcess', $row['uid'], $keys[$i]);
						$confArray = $mergedConf['config'];
						$confFunc = $mergedConf['func'];
						$confArray = $this->parseTypoScriptConfiguration($confArray, $wfqbeArray, $flag);
						eval('$mA["###FIELD_".$keys[$i]."###"]=$this->cObj->'.$confFunc.'($confArray);');
					}	elseif ($this->conf['globalCustomProcess.'][$keys[$i]]) {
						$mergedConf = $this->manageTyposcriptAlternatives($flag, 'globalCustomProcess', $row['uid'], $keys[$i]);
						$confArray = $mergedConf['config'];
						$confFunc = $mergedConf['func'];
						$confArray = $this->parseTypoScriptConfiguration($confArray, $wfqbeArray, $flag);
						eval('$mA["###FIELD_".$keys[$i]."###"]=$this->cObj->'.$confFunc.'($confArray);');
					}	else	{
						$mA["###FIELD_".$keys[$i]."###"]=$array[$keys[$i]];
					}
				}
			}

			if($flag%2==0)
				$mA["###WFQBE_CLASS###"]=$this->conf['classes.']['even'];
			else
				$mA["###WFQBE_CLASS###"]=$this->conf['classes.']['odd'];
			$flag++;

			$listaRighe.=$this->cObj->substituteMarkerArray($templateLista, $mA);
		}

		$listaRighe = $this->cObj->substituteSubpart($template,"###DATA_TEMPLATE###",$listaRighe,$recursive=0,$keepMarker=0);



		// headers management
		if (is_array($keys) && count($keys)>0)	{
			$i = 0;
			foreach ($keys as $col){
				if ($this->conf['results.']['enableOrderByHeaders']==1)
					$enableOrderByHeaders = 1;
				else
					$enableOrderByHeaders = 0;

				//siccome $key identifica una posizione con l'indice o in modo associativo utilizzo la funzione is_int() per non visualizzare
				//l'intestazione in forma numerica(ha pi� senso visualizzare quella associativa che � il nome della colonna della tabella).
				if ($this->conf["customHeader."][$row['uid']."."][$i]!="" || $this->conf["customHeader."][$row['uid']."."][$col]!="")	{
					if ($this->conf["customHeader."][$row['uid']."."][$col]!="")	{
						$confCustomHeader = $this->conf["customHeader."][$row['uid']."."][$col.'.'];
						$confCustomHeaderObj = $this->conf["customHeader."][$row['uid']."."][$col];
					}	else	{
						$confCustomHeader = $this->conf["customHeader."][$row['uid']."."][$i.'.'];
						$confCustomHeaderObj = $this->conf["customHeader."][$row['uid']."."][$i];
					}
					$wfqbeArray = array();
					$wfqbeArray['###WFQBE_NAME_'.$key.'###'] = $col;
					$confArray = $this->parseTypoScriptConfiguration($confCustomHeader, $wfqbeArray);
					eval('$mA["###HEADER_".$col."###"]=$this->cObj->'.$confCustomHeaderObj.'($confArray);');
					if ($confArray['enableOrderByHeaders']==1)
						$enableOrderByHeaders = 1;
					elseif ($confArray['enableOrderByHeaders']==0 && t3lib_div::testInt($confArray['enableOrderByHeaders']))
						$enableOrderByHeaders = 0;
				}	else	{
					$mA["###HEADER_".$col."###"]= $col;
				}

				if ($enableOrderByHeaders && t3lib_div::_GP('type')!=181)	{
					$mode = 'ASC';
					if ($this->pibase->piVars['orderby']['field']==$col && $this->pibase->piVars['orderby']['mode']=='ASC')
						$mode = 'DESC';
					$orderLink = array();
					$orderLink['parameter'] = $GLOBALS['TSFE']->id;
					$orderLink['addQueryString'] = 1;
					$orderLink['addQueryString.']['method'] = 'POST,GET';
					$orderLink['addQueryString.']['exclude'] = 'id';
					$orderLink['additionalParams'] = '&tx_wfqbe_pi1[orderby][mode]='.$mode.'&tx_wfqbe_pi1[orderby][field]='.$col;
					$mA["###HEADER_".$col."###"] = $this->cObj->typolink($mA["###HEADER_".$col."###"], $orderLink);
				}

				$i++;
			}
		}


		$mA["###DESCRIPTION###"]=$this->conf['ff_data']['caption'];
		$mA["###SUMMARY###"]=$this->conf['ff_data']['summary'];
		
		$mA['###WFQBE_NUMROWS###'] = $numRows;

		if ($numPages<2)
			$listaRighe = $this->cObj->substituteSubpart($listaRighe, '###BROWSE_TEMPLATE###', '', 1,0);
		else	{
			$this->showBrowser($mA, $numPages, $numRows, $actualPage, $row['uid']);
		}

		$listaRighe =$this->cObj->substituteMarkerArray($listaRighe, $mA);
		$content.=$listaRighe;

		return $content;
	}


	/**
	 * This function is used for parsing the TS fields configuration and to substitute the markers with the field value
	 */
	function parseTypoScriptConfiguration($confArray, $wfqbeArray)	{
		if (is_array($confArray) && is_array($wfqbeArray))	{
			foreach ($confArray as $k => $value)	{
				if (is_array($value))
					$confArray[$k] = $this->parseTypoScriptConfiguration($value, $wfqbeArray);
				elseif (strpos($value, "###WFQBE_FIELD_")!==false)	{
					$confArray[$k] = $this->cObj->substituteMarkerArray($value, $wfqbeArray);
				}
			}
		}
		return $confArray;
	}
	
	
	/**
	 * 
	 * This function is used to merge the alternative typoscript configurations (wfqbeFirst, wfqbeLast, wfqbeEven, wfqbeOdd)
	 * @param int $flag: record actualy managed
	 * @param string $mode: customProcess, customQuery, customHeader
	 * @param string $code: uid or label of the query record
	 * @param string $key: field to be managed
	 */
	function manageTyposcriptAlternatives($flag, $mode, $code, $key)	{
		if ($flag==0 && $this->conf[$mode."."][$code."."][$key."."]['wfqbeFirst']!='')	{
			$func = $this->conf[$mode."."][$code."."][$key."."]['wfqbeFirst'];
			$config = $this->conf[$mode."."][$code."."][$key."."]['wfqbeFirst.'];
		}	elseif (($flag==($this->conf['ff_data']['recordsForPage']-1) || $flag==($this->conf['wf_data']['queryNumRows']-1)) && $this->conf[$mode."."][$code."."][$key."."]['wfqbeLast']!='')	{
			$func = $this->conf[$mode."."][$code."."][$key."."]['wfqbeLast'];
			$config = $this->conf[$mode."."][$code."."][$key."."]['wfqbeLast.'];
		}	elseif ($flag%2==0 && $this->conf[$mode."."][$code."."][$key."."]['wfqbeEven']!='')	{
			$func = $this->conf[$mode."."][$code."."][$key."."]['wfqbeEven'];
			$config = $this->conf[$mode."."][$code."."][$key."."]['wfqbeEven.'];
		}	elseif ($flag%2==1 && $this->conf[$mode."."][$code."."][$key."."]['wfqbeOdd']!='')	{
			$func = $this->conf[$mode."."][$code."."][$key."."]['wfqbeOdd'];
			$config = $this->conf[$mode."."][$code."."][$key."."]['wfqbeOdd.'];
		}	else	{
			$func = $this->conf[$mode."."][$code."."][$key];
			$config = $this->conf[$mode."."][$code."."][$key.'.'];
		}
		
		return array('config' => $config, 'func' => $func);
	}


	/**
	 * This function is used to retrieve the markers from a query
	 */
	function getTSMarkers($query)	{
		if (preg_match_all("/([#]{3})([a-z,A-Z,0-9,@,!,_]*)([#]{3})/",$query,$markers))
			return $markers[2];
		else
			return null;
	}



	/**
	 * This function is used to show the page browser
	 */
	function showBrowser(&$mA, $numPages, $numRows, $actualPage, $uid)	{
		$lA = array();
		$lA['###PAGE_RECORDS_TOTAL###'] = $numRows;
		$lA['###PAGE_RECORDS_FROM###'] = ($this->conf['ff_data']['recordsForPage']*($actualPage-1))+1;
		$lA['###PAGE_RECORDS_TO###'] = min(($this->conf['ff_data']['recordsForPage']*$actualPage), $numRows);
		$mA['###LABEL_PAGE_RECORDS###'] = $this->pibase->cObj->substituteMarkerArray($this->pibase->pi_getLL('pagebrowser_records'), $lA);

		$mA['###PAGE_TOTAL###'] = $numPages;
		$mA['###PAGE_ACTUAL###'] = (int)$actualPage;
		unset($this->pibase->piVars['wfqbe_results_query']);
		$this->pibase->piVars['showpage'][$uid] = $actualPage-1>0 ? $actualPage-1 : 1;
		$mA['###PAGE_PREV###'] = htmlentities($this->pibase->pi_linkTP_keepPIvars_url());
		$mA['###PAGE_PREV_TITLE###'] = 'title="'.$this->pibase->pi_getLL('prev').'"';
		$this->pibase->piVars['showpage'][$uid] = $actualPage+1<$numPages ? $actualPage+1 : $numPages;
		$mA['###PAGE_NEXT###'] = htmlentities($this->pibase->pi_linkTP_keepPIvars_url());
		$mA['###PAGE_NEXT_TITLE###'] = 'title="'.$this->pibase->pi_getLL('next').'"';

		$mA['###LABEL_NEXT_LINK###'] = $this->pibase->pi_getLL('next_link', '&gt;&gt;');
		$mA['###LABEL_PREV_LINK###'] = $this->pibase->pi_getLL('prev_link', '&lt;&lt;');
		$mA['###LABEL_PAGE###'] = $this->pibase->pi_getLL('page', 'Page');
		$mA['###LABEL_OF###'] = $this->pibase->pi_getLL('of', 'of');

		$mA['###PAGE_LIST###'] = '';
		for ($i=1; $i<=$numPages; $i++)	{
			if ($i==$actualPage)
				$mA['###PAGE_LIST###'] .= ' '.$i.' - ';
			else	{
				$this->pibase->piVars['showpage'][$uid] = $i;
				$mA['###PAGE_LIST###'] .= ' <a href="'.htmlentities($this->pibase->pi_linkTP_keepPIvars_url()).'" title="'.$this->pibase->pi_getLL('go_to_page').' '.$i.'">'.$i.'</a> - ';
			}
		}
		if ($mA['###PAGE_LIST###']!='')
			$mA['###PAGE_LIST###'] = substr($mA['###PAGE_LIST###'], 0, -3);
	}


}



if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/wfqbe/pi1/class.tx_wfqbe_results.php'])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/wfqbe/pi1/class.tx_wfqbe_results.php']);
}
?>