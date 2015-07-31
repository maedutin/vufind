<?php
/**
 * Ajax Controller Module
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2010.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category VuFind2
 * @package  Controller
 * @author   Chris Hallberg <challber@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_a_controller Wiki
 */
namespace CPK\Controller;

use MZKCommon\Controller\AjaxController as AjaxControllerBase;

/**
 * This controller handles global AJAX functionality
 *
 * @category VuFind2
 * @package  Controller
 * @author   Martin Kravec <Martin.Kravec@mzk.cz>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_a_controller Wiki
 */
class AjaxController extends AjaxControllerBase
{

    /**
     * Get Buy Links
     *
     * @author Martin Kravec <Martin.Kravec@mzk.cz>
     * 
     * @return \Zend\Http\Response
     */
    protected function getBuyLinksAjax()
    {
    	// Antikvariaty
    	$parentRecordID = $this->params()->fromQuery('parentRecordID');
    	$recordID = $this->params()->fromQuery('recordID');
    	
    	$recordLoader = $this->getServiceLocator()->get('VuFind\RecordLoader');
    		
    	$parentRecordDriver = $recordLoader->load($parentRecordID);
    	$recordDriver = $recordLoader->load($recordID);
    	
    	$antikvariatyLink = $parentRecordDriver->getAntikvariatyLink();
    	
    	// GoogleBooks & Zbozi.cz
    	$wantItFactory = $this->getServiceLocator()->get('WantIt\Factory');
    	$buyChoiceHandler = $wantItFactory->createBuyChoiceHandlerObject($recordDriver);
    	
    	$gBooksLink = $buyChoiceHandler->getGoogleBooksVolumeLink();
    	$zboziLink = $buyChoiceHandler->getZboziLink();
    	
    	$buyChoiceLinksCount = 0;
    	
    	if ($gBooksLink) {
    		++$buyChoiceLinksCount;
    	}
    	
    	if ($zboziLink) {
    		++$buyChoiceLinksCount;
    	}
    	
    	if ($antikvariatyLink) {
    		++$buyChoiceLinksCount;
    	}
    	
		$vars[] = array(
			'gBooksLink'		=> $gBooksLink ?: '',
 			'zboziLink'			=> $zboziLink ?: '',
			'antikvariatyLink'	=> $antikvariatyLink ?: '',
			'buyLinksCount'		=> $buyChoiceLinksCount,
		);
        
        // Done
        return $this->output($vars, self::STATUS_OK);
    }
    
    /**
     * Returns subfileds of MARC 996 field for specific recordID
     *
     * @param	string	$_POST['record']
     * @param	string	$_POST['field']
     * @param	string	$_POST['subfields'] Comma-separated subfileds
     *
     * @return	array	$subfieldsValues	space-separated subfields values
     */
    public function getMarc996ArrayAjax()
    {
    	$recordID = $this->params()->fromQuery('recordID');
    	$field = $this->params()->fromQuery('field');
    	$subfieldsArray = explode(",", $this->params()->fromQuery('subfields'));
    
    	$recordLoader = $this->getServiceLocator()->get('VuFind\RecordLoader');
    		
    	$recordDriver = $recordLoader->load($recordID);
    	$arr = $recordDriver->get996($subfieldsArray);
    
    	$vars[] = array(
    		'arr' => $arr,
    	);
    	
    	// Done
    	return $this->output($vars, self::STATUS_OK);
    }
    
    /**
     * Downloads SFX JIB content for current record.
     * @param	string	$institute	Institute shortcut
     *
     * @return	array
     */
    public function callSfxJibAjax()
    {
    	$institute = $this->params()->fromQuery('institute');
    	if (! $institute)
    		$institute = 'ANY';
    	
    	$recordID = $this->params()->fromQuery('recordID');
    	$recordLoader = $this->getServiceLocator()->get('VuFind\RecordLoader');
    	$recordDriver = $recordLoader->load($recordID);
    	
    	$wantItFactory = $this->getServiceLocator()->get('WantIt\Factory');
    	$electronicChoiceHandler = $wantItFactory->createElectronicChoiceHandlerObject($recordDriver);
    
    	$jibArrayResult = $electronicChoiceHandler->downloadSfxJibResult($institute);
    
    	$vars[] = array(
    		'jib' => $jibArrayResult,
    	);
    	 
    	// Done
    	return $this->output($vars, self::STATUS_OK);
    }
}