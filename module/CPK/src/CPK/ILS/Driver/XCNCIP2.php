<?php
/**
 * XC NCIP Toolkit (v2) ILS Driver
 *
 * PHP version 5
 *
 * Copyright (C) Villanova University 2011.
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
 * @package  ILS_Drivers
 * @author   Matus Sabik <sabik@mzk.cz>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
namespace CPK\ILS\Driver;

use VuFind\Exception\ILS as ILSException, DOMDocument, Zend\XmlRpc\Value\String;

/* TODO List
 *
 * Parse and print problems into regular output on current page:
            $_ENV['exception'] = $message;
 *
 * Check all functionalities of these services:
 * LookupItem
 * LookupItemSet
 * LookupUser
 * LookupAgency
 * LookupRequest
 * RequestItem
 * - placeHold()
 * CancelRequestItem
 * - cancelHolds()
 * RenewItem
 * - renewMyItems()
 *
 */
/**
 * XC NCIP Toolkit (v2) ILS Driver
 *
 * @category VuFind2
 * @package ILS_Drivers
 * @author Matus Sabik <sabik@mzk.cz>
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public
 *          License
 * @link http://vufind.org/wiki/vufind2:building_an_ils_driver Wiki
 */
class XCNCIP2 extends \VuFind\ILS\Driver\AbstractBase implements \VuFindHttp\HttpServiceAwareInterface
{

    /**
     * HTTP service
     *
     * @var \VuFindHttp\HttpServiceInterface
     */
    protected $httpService = null;

    protected $requests = null;

    /**
     * Set the HTTP service to be used for HTTP requests.
     *
     * @param HttpServiceInterface $service
     *            HTTP service
     *
     * @return void
     */
    public function setHttpService(\VuFindHttp\HttpServiceInterface $service)
    {
        $this->httpService = $service;
    }

    /**
     * Initialize the driver.
     *
     * Validate configuration and perform all resource-intensive tasks needed to
     * make the driver active.
     *
     * @throws ILSException
     * @return void
     */
    public function init()
    {
        if (empty($this->config)) {
            throw new ILSException('Configuration needs to be set.');
        }
        $this->requests = new NCIPRequests();
    }

    /**
     * Send an NCIP request.
     *
     * @param string $xml
     *            XML request document
     *
     * @return object SimpleXMLElement parsed from response
     */
    protected function sendRequest($xml)
    {
        $xml = str_replace('BOA001.', 'MZK01', $xml); // Conversion BOA to MZK

        // TODO: delete this part - begin
                                                      // This is only for development purposes.
        if (! $this->isValidXMLAgainstXSD($xml)) {
            throw new ILSException('Not valid XML request!');
        }
        // delete this part - end

        // Make the NCIP request:
        try {
            $client = $this->httpService->createClient($this->config['Catalog']['url']);
            $client->setRawBody($xml);
            $client->setEncType('application/xml; "charset=utf-8"');
            $client->setMethod('POST');

            if ($this->config['Catalog']['hasUntrustedSSL']) {
                // Do not verify SSL certificate
                $client->setOptions(array(
                    'adapter' => 'Zend\Http\Client\Adapter\Curl',
                    'curloptions' => array(
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_SSL_VERIFYHOST => false,
                        CURLOPT_SSL_VERIFYPEER => false
                    )
                ));
            } elseif ($this->config['Catalog']['cacert']) {
                $client->setOptions(array(
                    'adapter' => 'Zend\Http\Client\Adapter\Curl',
                    'curloptions' => array(
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_CAINFO => $this->config['Catalog']['cacert']
                    )
                ));
            }

            $result = $client->send();
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $_ENV['exception'] = $message;
            throw new ILSException($message);
        }

        if (! $result->isSuccess()) {
            $message = 'HTTP error';
            $_ENV['exception'] = $message;
            throw new ILSException($message);
        }

        // Process the NCIP response:
        $body = $result->getBody();
        $response = @simplexml_load_string($body);

        if (! is_a($response, 'SimpleXMLElement')) {
            $message = "Problem parsing XML";
            $_ENV['exception'] = $message;
            throw new ILSException($message);
        }
        $response->registerXPathNamespace('ns1', 'http://www.niso.org/2008/ncip');

        if (! $this->isValidXMLAgainstXSD($response)) {
            $message = 'Not valid XML response!';
            $_ENV['exception'] = $message;
            throw new ILSException($message);
        }

        if (! $this->isCorrect($response)) {
            // TODO chcek problem type
            // return null;
            // var_dump($response->AsXML());
            // throw new ILSException('Problem has occured!');
        }
        $response = str_replace('MZK01', 'BOA001.', $response->AsXML());
        $response = simplexml_load_string($response);
        return $response;
    }

    /**
     * Cancel Holds
     *
     * Cancels a list of holds for a specific patron.
     *
     * @param array $cancelDetails
     *            - array with two keys: patron (array from patronLogin method) and
     *            details (an array of strings returned by the driver's getCancelHoldDetails method)
     *
     * @throws ILSException
     * @return array Status of canceled holds.
     */
    public function cancelHolds($cancelDetails)
    {
        $holds = $this->getMyHolds($cancelDetails['patron']);
        $items = array();
        foreach ($cancelDetails['details'] as $recent) {
            foreach ($holds as $onehold) {
                if ($onehold['id'] == $recent) {
                    $item_id = $onehold['item_id'];
                    break;
                }
            }
            if (empty($item_id))
                return null;
            $request = $this->requests->cancelHold($item_id, $cancelDetails['patron']['id']);
            $response = $this->sendRequest($request);

            $items[$item_id] = array(
                'success' => true,
                'status' => '',
                'sysMessage' => ''
            );
        }
        $retVal = array(
            'count' => count($items),
            'items' => $items
        );
        return $retVal;
    }

    /**
     * Renew My Items
     *
     * his method renews a list of items for a specific patron.
     *
     * @param array $renewDetails
     *            Two keys patron and details.
     *
     * @throws ILSException
     * @return array An associative array with two keys: blocks and details.
     */
    public function renewMyItems($renewDetails)
    {
        $result = array();
        foreach ($renewDetails['details'] as $item) {
            $request = $this->requests->renewMyItem($renewDetails['patron'], $item);
            $response = $this->sendRequest($request);
            $date = $response->xpath('ns1:RenewItemResponse/ns1:DateForReturn');
            $result[$item] = array(
                'success' => true,
                'new_date' => (string) $date[0],
                'new_time' => (string) $date[0], // used the same like previous
                'item_id' => $item,
                'sysMessage' => ''
            );
        }
        return array(
            'blocks' => false,
            'details' => $result
        );
    }

    public function getAccruedOverdue($user)
    {
        // TODO testing purposes
        return 12340;
        $sum = 0;
        $xml = $this->alephWebService->doRestDLFRequest(array(
            'patron',
            $user['id'],
            'circulationActions'
        ), null);
        foreach ($xml->circulationActions->institution as $institution) {
            $cashNote = (string) $institution->note;
            $matches = array();
            if (preg_match("/Please note that there is an additional accrued overdue items fine of: (\d+\.?\d*)\./", $cashNote, $matches) === 1) {
                $sum = $matches[1];
            }
        }
        return $sum;
    }

    public function getPaymentURL()
    {
        return 'www.mzk.cz';
    }

    /**
     * Get Renew Details
     *
     * This method returns a string to use as the input form value for renewing each hold item.
     *
     * @param array $checkOutDetails
     *            - One of the individual item arrays returned by the getMyTransactions method.
     *
     * @throws ILSException
     * @return string Used as the input form value for renewing each item;
     *         you can pass any data that is needed by your ILS to identify the transaction to renew –
     *         the output of this method will be used as part of the input to the renewMyItems method.
     */
    public function getRenewDetails($checkOutDetails)
    {
        return $checkOutDetails['id'];
    }

    /**
     * Get Cancel Hold Details
     *
     * This method returns a string to use as the input form value for cancelling each hold item.
     *
     * @param array $holdDetails
     *            - One of the individual item arrays returned by the getMyHolds method.
     *
     * @throws ILSException
     * @return string Used as the input value for cancelling each hold item;
     *         any data needed to identify the hold –
     *         the output will be used as part of the input to the cancelHolds method.
     */
    public function getCancelHoldDetails($holdDetails)
    {
        return $holdDetails['id'];
    }

    /**
     * Given a chunk of the availability response, extract the values needed
     * by VuFind.
     *
     * @param array $current
     *            Current XCItemAvailability chunk.
     *            array $bibinfo Information about record.
     *
     * @return array
     */
    protected function getHoldingsForChunk($current, $bibinfo)
    {
        // Extract details from the XML:
        $status = (string) $current->xpath('ns1:ItemOptionalFields/ns1:CirculationStatus')[0];

        $id = (string) $bibinfo->xpath('ns1:BibliographicId/ns1:BibliographicItemId/ns1:BibliographicItemIdentifier')[0];
        $itemIdentifierCode = (string) $current->xpath('ns1:ItemId/ns1:ItemIdentifierType')[0];

        $parsingLoans = $current->xpath('ns1:LoanedItem') != null;

        if ($itemIdentifierCode == 'Accession Number') {

            $item_id = (string) $current->xpath('ns1:ItemId/ns1:ItemIdentifierValue')[0];
        }

        // Pick out the permanent location (TODO: better smarts for dealing with
        // temporary locations and multi-level location names):

        $locationNameInstance = $current->xpath('ns1:ItemOptionalFields/ns1:Location/ns1:LocationName/ns1:LocationNameInstance');

        foreach ($locationNameInstance as $recent) {
            // FIXME: Create config to map location abbreviations of each institute into human readable values

            $locationLevel = (string) $recent->xpath('ns1:LocationNameLevel')[0];

            if ($locationLevel == 4) {
                $department = (string) $recent->xpath('ns1:LocationNameValue')[0];
            } else
                if ($locationLevel == 3) {
                    $sublibrary = (string) $recent->xpath('ns1:LocationNameValue')[0];
                } else {
                    $locationInBuilding = (string) $recent->xpath('ns1:LocationNameValue')[0];
                }
        }

        // Get both holdings and item level call numbers; we'll pick the most
        // specific available value below.
        // $holdCallNo = $current->xpath('ns1:HoldingsSet/ns1:CallNumber');
        $itemCallNo = (string) $current->xpath('ns1:ItemOptionalFields/ns1:ItemDescription/ns1:CallNumber')[0];
        // $itemCallNo = (string)$itemCallNo[0];

        $bibliographicItemIdentifierCode = (string) $current->xpath('ns1:ItemOptionalFields/ns1:BibliographicDescription/ns1:BibliographicRecordId/ns1:BibliographicRecordIdentifierCode')[0];

        if ($bibliographicItemIdentifierCode == 'Legal Deposit Number') {

            $barcode = (string) $current->xpath('ns1:ItemOptionalFields/ns1:BibliographicDescription/ns1:BibliographicRecordId/ns1:BibliographicRecordIdentifier')[0];
        }

        $numberOfPieces = (string) $current->xpath('ns1:ItemOptionalFields/ns1:ItemDescription/ns1:NumberOfPieces')[0];

        $holdQueue = (string) $current->xpath('ns1:ItemOptionalFields/ns1:HoldQueueLength')[0];

        $itemRestriction = (string) $current->xpath('ns1:ItemOptionalFields/ns1:ItemUseRestrictionType')[0];

        $available = $status === 'Available On Shelf';

        // TODO Exists any clean way to get the due date without additional request?

        if (! empty($locationInBuilding))
            $onStock = substr($locationInBuilding, 0, 5) == 'Stock';
        else
            $onStock = false;

        $onStock = true;

        $restrictedToLibrary = ($itemRestriction == 'In Library Use Only');

        $monthLoanPeriod = ($itemRestriction == 'Limited Circulation, Normal Loan Period') || empty($itemRestriction);

        // FIXME: Add link logic
        $link = false;
        if ($onStock && $restrictedToLibrary) {
            // This means the reader needs to place a request to prepare the
            // item -> pick up the item from stock & bring it to circulation
            // desc
            // E.g. https://vufind.mzk.cz/Record/MZK01-000974548#bd
            $link = $this->createLinkFromAlephItemId($item_id);
        } else
            if ($onStock && $monthLoanPeriod) {
                // Pickup from stock & prepare for month loan
                $link = $this->createLinkFromAlephItemId($item_id);
            } else
                if (! $available && ! $onStock) {
                    // Reserve item
                    $link = $this->createLinkFromAlephItemId($item_id);
                }
        // End of FIXME

        return array(
            'id' => empty($id) ? "" : $id,
            'availability' => empty($available) ? false : $available ? true : false,
            'status' => empty($status) ? "" : $status,
            'location' => empty($locationInBuilding) ? "" : $locationInBuilding,
            'sub_lib_desc' => empty($sublibrary) ? '' : $sublibrary,
            'department' => empty($department) ? '' : $department,
            'reserve' => "",
            'callnumber' => "",
            'collection_desc' => "",
            'duedate' => empty($dueDate) ? "" : $dueDate,
            'returnDate' => false,
            'number' => empty($numberOfPieces) ? "" : $numberOfPieces,
            'requests_placed' => empty($holdQueue) ? "" : $holdQueue,
            'barcode' => empty($barcode) ? "" : $barcode,
            'notes' => "",
            'summary' => "",
            'supplements' => "",
            'indexes' => "",
            'is_holdable' => "",
            'holdtype' => "",
            'addLink' => "",
            'link' => $link,
            'item_id' => empty($item_id) ? "" : $item_id,
            'holdOverride' => "",
            'addStorageRetrievalRequestLink' => "",
            'addILLRequestLink' => ""
        );
    }

    private function createLinkFromAlephItemId($item_id)
    {
        // Input: MZK01000974548-MZK50000974548000010
        // Output: MZK01-000974548/ExtendedHold?barcode=MZK50000974548000020
        // Hold?id=MZK01-001422752&item_id=MZK50001457754000010&hashKey=451f0e3f0112decdadc4a9e507a60cfb#tabnav
        $itemIdParts = explode("-", $item_id);

        /*
         * $id = substr($itemIdParts[0], 0, 5) . "-" . substr($itemIdParts[0], 5);
         * $link .= $id . '/Hold?id=' . $id . '&item_id=';
         * $link .= $itemIdParts[1];
         */
        $link .= $itemIdParts[0] . '/Hold?id=' . $itemIdParts[0] . '&item_id=' . $itemIdParts[1];
        $link .= '#tabnav';
        return $link;
    }

    /*
     * public function getHoldLink ($item_id)
     * {
     * // TODO testing purposes
     * $itemIdParts = explode("-", $item_id);
     *
     * $id = substr($itemIdParts[0], 0, 5) . "-" . substr($itemIdParts[0], 5);
     * $link .= $id . '/Hold?id=' . $id . '&item_id=';
     * $link .= $itemIdParts[1];
     * $link .= '#tabnav';
     * return 'odlisenie/Hold?id=MZK01-001422752&item_id=MZK50001457754000010#tabnav';
     * return $link;
     * }
     */
    public function placeHold($holdDetails)
    {
        // var_dump($holdDetails);
        $request = $this->requests->placeHold($holdDetails);
        // var_dump($request);
        $response = $this->sendRequest($request);
        // var_dump($response);
        return array(
            'success' => true,
            'sysMessage' => ''
        );
    }

    public function getConfig($func)
    {
        if ($func == "Holds") {
            if (isset($this->config['Holds'])) {
                return $this->config['Holds'];
            }
            return array(
                "HMACKeys" => "id:item_id",
                "extraHoldFields" => "comments:requiredByDate:pickUpLocation",
                "defaultRequiredDate" => "0:1:0"
            );
        }
        if ($func == "ILLRequests") {
            return array(
                "HMACKeys" => "id:item_id"
            );
        } else {
            return array();
        }
    }

    public function getPickUpLocations($patron = null, $holdInformation = null)
    {
        //FIXME
        return array();
    }

    public function getDefaultPickUpLocation($patron = null, $holdInformation = null)
    {
        // TODO testing purposes
        return '1';
    }

    public function getMyHistory($patron, $currentLimit = 0)
    {
        $request = $this->requests->getMyHistory($patron);
        if ($request === null)
            return [];

        $response = $this->sendRequest($request);
        return $this->handleTransactions($response);
    }

    /**
     * Get Status
     *
     * This is responsible for retrieving the status information of a certain
     * record.
     *
     * @param string $id
     *            The record id to retrieve the holdings for
     *
     * @throws ILSException
     * @return mixed On success, an associative array with the following keys:
     *         id, availability (boolean), status, location, reserve,
     *         callnumber.
     */
    public function getStatus($id)
    {
        // TODO
        // For now, we'll just use getHolding, since getStatus should return a
        // subset of the same fields, and the extra values will be ignored.
        $holding = $this->getHolding($id);

        foreach ($holding as $recent) {
            $tmp[] = array_slice($recent, 0, 6);
        }
        return $tmp;
    }

    /**
     * Get Statuses
     *
     * This method calls getStatus for an array of records.
     *
     * @param
     *            array Array of bibliographic record IDs.
     *
     * @throws ILSException
     * @return array Array of return values from getStatus.
     */
    public function getStatuses($ids)
    {
        $retVal = array();
        foreach ($ids as $recent) {

            // FIXME: We shouldn't iterate as we can use LookupItemSet ..
            $retVal[] = $this->getStatus($recent);
        }
        return $retVal;
    }

    /**
     * Get Holding
     *
     * This is responsible for retrieving the holding information of a certain
     * record.
     *
     * @param string $id
     *            The record id to retrieve the holdings.
     * @param array $patron
     *            Patron data.
     *
     * @throws \VuFind\Exception\Date
     * @throws ILSException
     * @return array On success, an associative array with the following
     *         keys: id, availability (boolean), status, location, reserve,
     *         callnumber,
     *         duedate, number, barcode.
     */
    public function getHolding($id, array $patron = null)
    {
        // $id may have the form of "agencyId:bibId"
        $agencyId = false;
        $idSplitted = explode(':', $id);

        if (count($idSplitted) > 1) {
            $agencyId = $idSplitted[0];

            // Merge the rest of the array
            $idSplitted = array_slice($idSplitted, 1);
            $id = implode(':', $idSplitted);
        }

        unset($idSplitted);

        // FIXME: Is this not async iteration useful??
        $maxItemsCount = 5; // use null for unlimited count of items
        do {
            if (isset($nextItemToken[0]))
                $request = $this->requests->getHolding(array(
                    $id
                ), $maxItemsCount, (string) $nextItemToken[0]);
            else {
                $request = $this->requests->getHolding(array(
                    $id
                ), $maxItemsCount);
                $all_iteminfo = [];
            }

            $response = $this->sendRequest($request);
            if ($response == null)
                return null;

            $new_iteminfo = $response->xpath('ns1:LookupItemSetResponse/ns1:BibInformation/ns1:HoldingsSet/ns1:ItemInformation');
            $all_iteminfo = array_merge($all_iteminfo, $new_iteminfo);

            $nextItemToken = $response->xpath('ns1:LookupItemSetResponse/ns1:NextItemToken');
        } while ($this->isValidToken($nextItemToken));
        $bibinfo = $response->xpath('ns1:LookupItemSetResponse/ns1:BibInformation');
        $bibinfo = $bibinfo[0];

        // Build the array of holdings:
        $holdings = array();
        foreach ($all_iteminfo as $current) {
            $holdings[] = $this->getHoldingsForChunk($current, $bibinfo);
        }
        return $holdings;
    }

    /**
     * Get Purchase History
     *
     * This is responsible for retrieving the acquisitions history data for the
     * specific record (usually recently received issues of a serial).
     *
     * @param string $id
     *            The record id to retrieve the info for
     *
     * @throws ILSException
     * @return array An array with the acquisitions data on success.
     *         @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getPurchaseHistory($id)
    {
        // TODO
        return array();
    }

    /**
     * Patron Login
     *
     * This is responsible for authenticating a patron against the catalog.
     *
     * @param string $username
     *            The patron username
     * @param string $password
     *            The patron's password
     *
     * @throws ILSException
     * @return mixed Associative array of patron info on successful login,
     *         null on unsuccessful login.
     */
    public function patronLogin($username, $password)
    {
        // If password is null, than is user already logged in ..
        if ($password == null) {
            $temp = array(
                "id" => $username
            );
            return $this->getMyProfile($temp);
        }

        $request = $this->requests->patronLogin($username, $password);
        $response = $this->sendRequest($request);
        $id = $response->xpath('ns1:LookupUserResponse/ns1:UserId/ns1:UserIdentifierValue');
        $firstname = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:NameInformation/' . 'ns1:PersonalNameInformation/ns1:StructuredPersonalUserName/ns1:GivenName');

        $lastname = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:NameInformation/' . 'ns1:PersonalNameInformation/ns1:StructuredPersonalUserName/ns1:Surname');
        $email = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:UserAddressInformation/' . 'ns1:ElectronicAddress/ns1:ElectronicAddressData');
        if (! empty($id)) {
            $patron = array(
                'id' => empty($id) ? '' : (string) $id[0],
                'firstname' => empty($firstname) ? '' : (string) $firstname[0],
                'lastname' => empty($lastname) ? '' : (string) $lastname[0],
                'cat_username' => $username,
                'cat_password' => $password,
                'email' => empty($email) ? '' : (string) $email[0],
                'major' => null,
                'college' => null
            );
            return $patron;
        }
        return null;
    }

    /**
     * Get Patron Transactions
     *
     * This method queries the ILS for a patron's current checked out items.
     *
     * @param array $patron
     *            The patron array
     *
     * @throws ILSException
     * @return array Array of arrays, one for each item checked out by the
     *         specified account.
     */
    public function getMyTransactions($patron)
    {
        $request = $this->requests->getMyTransactions($patron);
        $response = $this->sendRequest($request);
        return $this->handleTransactions($response, $patron);
    }

    private function handleTransactions($response, $patron)
    {
        $retVal = array();
        $list = $response->xpath('ns1:LookupUserResponse/ns1:LoanedItem');

        //$listVal = $response->asXML();
        foreach ($list as $current) {
            $item_id = $current->xpath('ns1:ItemId/ns1:ItemIdentifierValue');
            $dateDue = $current->xpath('ns1:DateDue');
            $parsedDate = strtotime((string) $dateDue[0]);
            /*
             * $item_id = $current->xpath(
             * 'ns1:Ext/ns1:BibliographicDescription/' .
             * 'ns1:BibliographicRecordId/ns1:BibliographicRecordIdentifier');
             * $bibliographicId = substr(explode("-", (string) $item_id[0])[0], 5);
             * $amount = $current->xpath('ns1:Amount');
             * $reminderLevel = $current->xpath('ns1:ReminderLevel');
             * $ext = $current->xpath('ns1:Ext');
             */
            // TODO: is Renewable?
            $additRequest = $this->requests->getItemInfo((string) $item_id[0]);
            $additResponse = $this->sendRequest($additRequest);
            $isbn = $additResponse->xpath('ns1:LookupItemResponse/ns1:ItemOptionalFields/ns1:BibliographicDescription/' . 'ns1:BibliographicRecordId/ns1:BibliographicRecordIdentifier');
            $bib_id = $additResponse->xpath('ns1:LookupItemResponse/ns1:ItemOptionalFields/ns1:BibliographicDescription/' . 'ns1:ComponentId/ns1:ComponentIdentifier');
            $author = $additResponse->xpath('ns1:LookupItemResponse/ns1:ItemOptionalFields/ns1:BibliographicDescription/' . 'ns1:Author');
            $title = $additResponse->xpath('ns1:LookupItemResponse/ns1:ItemOptionalFields/ns1:BibliographicDescription/' . 'ns1:Title');
            $mediumType = $additResponse->xpath('ns1:LookupItemResponse/ns1:ItemOptionalFields/ns1:BibliographicDescription/' . 'ns1:MediumType');

            $dateDue = date('j. n. Y', $parsedDate);

            $retVal[] = array(
                'cat_username' => $patron['cat_username'],
                'duedate' => empty($dateDue) ? '' : $dateDue,
                'id' => empty($item_id) ? '' : (string) $item_id[0],
                'barcode' => '', // TODO
                                 // 'renew' => '',
                                 // 'renewLimit' => '',
                'request' => empty($request) ? '' : (string) $request[0],
                'volume' => '',
                'author' => empty($author) ? '' : (string) $author[0],
                'publication_year' => '', // TODO
                'renewable' => empty($request) ? false : true,
                'message' => '',
                'title' => empty($title) ? '' : (string) $title[0],
                'item_id' => empty($item_id) ? '' : (string) $item_id[0],
                'institution_name' => '',
                'isbn' => empty($isbn) ? '' : (string) $isbn[0],
                'issn' => '',
                'oclc' => '',
                'upc' => '',
                'borrowingLocation' => ''
            );
        }
        return $retVal;
    }

    /**
     * Get Patron Fines
     *
     * This method queries the ILS for a patron's current fines.
     *
     * @param array $patron
     *            The patron array
     *
     * @return array Array of arrays containing fines information.
     */
    public function getMyFines($patron)
    {
        $request = $this->requests->getMyFines($patron);
        $response = $this->sendRequest($request);

        $list = $response->xpath('ns1:LookupUserResponse/ns1:UserFiscalAccount/ns1:AccountDetails');

        $fines = array();
        foreach ($list as $current) {
            $amount = $current->xpath('ns1:FiscalTransactionInformation/ns1:Amount/ns1:MonetaryValue');
            $type = $current->xpath('ns1:FiscalTransactionInformation/ns1:FiscalTransactionType');
            $date = $current->xpath('ns1:AccrualDate');
            $desc = $current->xpath('ns1:FiscalTransactionInformation/ns1:FiscalTransactionDescription');
            /*
             * This is an item ID, not a bib ID, so it's not actually useful:
             * $tmp = $current->xpath(
             * 'ns1:FiscalTransactionInformation/ns1:ItemDetails/' .
             * 'ns1:ItemId/ns1:ItemIdentifierValue' ); $id = (string)$tmp[0];
             */

            $parsedDate = strtotime((string) $date[0]);
            $date = date('j. n. Y', $parsedDate);
            $amount_int = (int) $amount[0] * (- 1);
            $sum += $amount_int;

            $fines[] = array(
                'amount' => (string) $amount_int,
                'checkout' => $date,
                'fine' => (string) $desc[0],
                'balance' => (string) $sum,
                'createdate' => '',
                'duedate' => '',
                'id' => (string) $type[0]
            );
        }
        // TODO vymaz
        /*
         * $fines[] = array(
         * 'amount' => '10',
         * 'checkout' => '03. 08. 2014',
         * 'fine' => 'takto to bude vyzerat',
         * 'balance' => '-260',
         * 'createdate' => '01. 08. 2014',
         * 'duedate' => '09. 09. 2014',
         * 'id' => 'MZK01-001276830',
         * );
         */
        return $fines;
    }

    /**
     * Get Patron's current holds - books which are reserved.
     *
     * @param array $patron
     *            The patron array
     *
     * @return array Array of arrays, one for each hold.
     */
    public function getMyHolds($patron)
    {
        $request = $this->requests->getMyHolds($patron);
        $response = $this->sendRequest($request);

        $retVal = array();
        $list = $response->xpath('ns1:LookupUserResponse/ns1:RequestedItem');

        foreach ($list as $current) {
            $xml = $current->asXML();
            $type = $current->xpath('ns1:RequestType');
            $id = $current->xpath('ns1:BibliographicId/ns1:BibliographicItemId/ns1:BibliographicItemIdentifier');
            $location = $current->xpath('ns1:PickupLocation');
            $reqnum = $current->xpath('ns1:RequestId/ns1:RequestIdentifierValue');
            $expire = $current->xpath('ns1:PickupExpiryDate');
            $create = $current->xpath('ns1:DatePlaced');
            $position = $current->xpath('ns1:HoldQueuePosition');
            $item_id = $current->xpath('ns1:ItemId/ns1:ItemIdentifierValue');
            $title = $current->xpath('ns1:Title');
            $bib_id = empty($id) ? null : explode('-', (string) $id[0])[0];
            // $bib_id = substr_replace($bib_id, '-', 5, 0); // number 5 is position

            $parsedDate = empty($create) ? '' : strtotime($create[0]);
            $create = date('j. n. Y', $parsedDate);
            $parsedDate = empty($expire) ? '' : strtotime($expire[0]);
            $expire = date('j. n. Y', $parsedDate);
            $retVal[] = array(
                'type' => empty($type) ? '' : (string) $type[0],
                'id' => empty($bib_id) ? '' : $bib_id,
                'location' => empty($location) ? '' : (string) $location[0],
                'reqnum' => empty($reqnum) ? '' : (string) $reqnum[0],
                'expire' => empty($expire) ? '' : $expire,
                'create' => empty($create) ? '' : $create,
                'position' => empty($position) ? '' : (string) $position[0],
                'available' => false, // true means item is ready for check out
                'item_id' => empty($item_id) ? '' : (string) $item_id[0],
                'volume' => '',
                'publication_year' => '',
                'title' => empty($title) ? '' : (string) $title[0],
                'isbn' => '',
                'issn' => '',
                'oclc' => '',
                'upc' => ''
            );
        }
        return $retVal;
    }

    /**
     * Get Patron Profile
     *
     * This is responsible for retrieving the profile for a specific patron.
     *
     * @param array $patron
     *            The patron array
     *
     * @throws ILSException
     * @return array Array of the patron's profile data on success.
     */
    public function getMyProfile($patron)
    {
        $request = $this->requests->getMyProfile($patron);
        $response = $this->sendRequest($request);

        $name = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:NameInformation/' . 'ns1:PersonalNameInformation/ns1:StructuredPersonalUserName/ns1:GivenName');
        $surname = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:NameInformation/' . 'ns1:PersonalNameInformation/ns1:StructuredPersonalUserName/ns1:Surname');
        $address1 = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:UserAddressInformation/' . 'ns1:PhysicalAddress/ns1:StructuredAddress/ns1:Street');
        $address2 = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:UserAddressInformation/' . 'ns1:PhysicalAddress/ns1:StructuredAddress/ns1:HouseName');
        $city = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:UserAddressInformation/' . 'ns1:PhysicalAddress/ns1:StructuredAddress/ns1:Locality');
        $country = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:UserAddressInformation/' . 'ns1:PhysicalAddress/ns1:StructuredAddress/ns1:Country');
        $zip = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:UserAddressInformation/' . 'ns1:PhysicalAddress/ns1:StructuredAddress/ns1:PostalCode');
        $electronicAddress = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:UserAddressInformation/' . 'ns1:ElectronicAddress');
        foreach ($electronicAddress as $recent) {
            if ($recent->xpath('ns1:ElectronicAddressType')[0] == 'tel') {
                $phone = $recent->xpath('ns1:ElectronicAddressData');
            }
        }
        $group = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:UserPrivilege/ns1:UserPrivilegeDescription');

        $blocksParsed = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:BlockOrTrap/ns1:BlockOrTrapType');

        $eppnScope = $this->config['Catalog']['eppnScope'];

        foreach ($blocksParsed as $block) {
            if (! empty($eppnScope)) {
                $blocks[$eppnScope] = (string) $block;
            } else
                $blocks[] = (string) $block;
        }

        $patron = array(
            'cat_username' => $patron['id'],
            'id' => $patron['id'],
            'firstname' => empty($name) ? '' : (string) $name[0],
            'lastname' => empty($surname) ? '' : (string) $surname[0],
            'address1' => empty($address1) ? '' : (string) $address1[0],
            'address2' => empty($address2) ? '' : (string) $address2[0],
            'city' => empty($city) ? '' : (string) $city[0],
            'country' => empty($country) ? '' : (string) $country[0],
            'zip' => empty($zip) ? '' : (string) $zip[0],
            'phone' => empty($phone) ? '' : (string) $phone[0],
            'group' => empty($group) ? '' : (string) $group[0],
            'blocks' => empty($blocks) ? array() : $blocks
        );
        return $patron;
    }


    /**
     * Get Patron BlocksOrTraps
     *
     * This is responsible for retrieving blocks of a specific patron.
     *
     * @param array $cat_username
     *            String of userId
     *
     * @throws ILSException
     * @return array Array of the patron's blocks in string.
     */
    public function getBlocks($cat_username)
    {
        $request = $this->requests->getBlocks($cat_username);
        $response = $this->sendRequest($request);

        $blocksParsed = $response->xpath('ns1:LookupUserResponse/ns1:UserOptionalFields/ns1:BlockOrTrap/ns1:BlockOrTrapType');

        $i = -1;
        foreach ($blocksParsed as $block) {
            $blocks[++$i] = (string) $block;
        }

        return $blocks;
    }

    /**
     * Get New Items
     *
     * Retrieve the IDs of items recently added to the catalog.
     *
     * @param int $page
     *            Page number of results to retrieve (counting starts at 1)
     * @param int $limit
     *            The size of each page of results to retrieve
     * @param int $daysOld
     *            The maximum age of records to retrieve in days (max. 30)
     * @param int $fundId
     *            optional fund ID to use for limiting results (use a value
     *            returned by getFunds, or exclude for no limit); note that
     *            "fund" may be a
     *            misnomer - if funds are not an appropriate way to limit your
     *            new item
     *            results, you can return a different set of values from
     *            getFunds. The
     *            important thing is that this parameter supports an ID returned
     *            by getFunds,
     *            whatever that may mean.
     *
     * @throws ILSException
     * @return array Associative array with 'count' and 'results' keys
     *         @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function getNewItems($page, $limit, $daysOld, $fundId = null)
    {
        // TODO
        return array();
    }

    /**
     * Get Funds
     *
     * Return a list of funds which may be used to limit the getNewItems list.
     *
     * @throws ILSException
     * @return array An associative array with key = fund ID, value = fund name.
     */
    public function getFunds()
    {
        // TODO
        return array();
    }

    /**
     * Get Departments
     *
     * Obtain a list of departments for use in limiting the reserves list.
     *
     * @throws ILSException
     * @return array An associative array with key = dept. ID, value = dept.
     *         name.
     */
    public function getDepartments()
    {
        // TODO
        return array();
    }

    /**
     * Get Instructors
     *
     * Obtain a list of instructors for use in limiting the reserves list.
     *
     * @throws ILSException
     * @return array An associative array with key = ID, value = name.
     */
    public function getInstructors()
    {
        // TODO
        return array();
    }

    /**
     * Get Courses
     *
     * Obtain a list of courses for use in limiting the reserves list.
     *
     * @throws ILSException
     * @return array An associative array with key = ID, value = name.
     */
    public function getCourses()
    {
        // TODO
        return array();
    }

    /**
     * Find Reserves
     *
     * Obtain information on course reserves.
     *
     * @param string $course
     *            ID from getCourses (empty string to match all)
     * @param string $inst
     *            ID from getInstructors (empty string to match all)
     * @param string $dept
     *            ID from getDepartments (empty string to match all)
     *
     * @throws ILSException
     * @return array An array of associative arrays representing reserve items.
     *         @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function findReserves($course, $inst, $dept)
    {
        // TODO
        return array();
    }

    /**
     * Get suppressed records.
     *
     * @throws ILSException
     * @return array ID numbers of suppressed records in the system.
     */
    public function getSuppressedRecords()
    {
        // TODO
        return array();
    }

    /**
     * Validate XML against XSD schema.
     *
     * @param string $XML
     *            or SimpleXMLElement $XML
     * @param
     *            $path_to_XSD
     *
     * @throws ILSException
     * @return boolean Returns true, if XML is valid.
     */
    protected function isValidXMLAgainstXSD($XML, $path_to_XSD = './module/VuFind/tests/fixtures/ils/xcncip2/schemas/v2.02.xsd')
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true); // Begin - Disable xml error messages.
        if (is_string($XML))
            $doc->loadXML($XML);
        else
            if (get_class($XML) == 'SimpleXMLElement')
                $doc->loadXML($XML->asXML());
            else
                throw new ILSException('Expected SimpleXMLElement or string containing XML.');
        libxml_clear_errors(); // End - Disable xml error messages.
        return $doc->schemaValidate($path_to_XSD);
    }

    /**
     * Check response from NCIP responder.
     * Check if $response is not containing problem tag.
     *
     * @param $response SimpleXMLElement
     *            Object
     *
     * @return boolean Returns true, if response is without problem.
     */
    protected function isCorrect($response)
    {
        $problem = $response->xpath('//ns1:Problem');
        if ($problem == null)
            return true;
        print_r($problem[0]->AsXML());
        return false;
    }

    /**
     * Validate next item token.
     * Check if $nextItemToken was set and contains data.
     *
     * @param
     *            array, at index [0] SimpleXMLElement Object
     *
     * @return boolean Returns true, if token is valid.
     */
    protected function isValidToken($nextItemToken)
    {
        if (isset($nextItemToken[0])) {
                return ! empty((string) $nextItemToken[0]);
        }
        return false;
    }
}

/**
 * Building NCIP requests version 2.02.
 *
 * @category VuFind2
 * @package ILS_Drivers
 * @author Matus Sabik <sabik@mzk.cz>
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public
 *          License
 */
class NCIPRequests
{

    protected $cpk_conversion = false;

    protected function cpkConvert($id) // Substituted by str_replace in method sendRequest.
    {
        if ($this->cpk_conversion) {
            $id = substr_replace($id, 'MZK01', 0, 7);
        }
        return $id;
    }

    /**
     * Build NCIP request XML for cancel holds.
     *
     * @param array $cancelDetails
     *            Patron's information and details about cancel request.
     *
     * @return string XML request
     */
    public function cancelHold($itemID, $patronID)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . '<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ns1:version' . '="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"><ns1:CancelRequestItem>' . '<ns1:UserId><ns1:UserIdentifierValue>' . htmlspecialchars($patronID) . '</ns1:UserIdentifierValue></ns1:UserId>' . '<ns1:ItemId><ns1:ItemIdentifierValue>' . htmlspecialchars($itemID) . '</ns1:ItemIdentifierValue></ns1:ItemId>' . '<ns1:RequestType ns1:Scheme="http://www.niso.org/ncip/v1_0/imp1/schemes/requesttype/requesttype.scm">Hold</ns1:RequestType>' . '<ns1:RequestScopeType ns1:Scheme="http://www.niso.org/ncip/v1_0/imp1/schemes/requestscopetype/requestscopetype.scm">Item</ns1:RequestScopeType>' . '</ns1:CancelRequestItem></ns1:NCIPMessage>';
        return $xml;
    }

    /**
     * Build NCIP request XML for item status information.
     *
     * @param array $idList
     *            IDs to look up.
     * @param string $resumption
     *            Resumption token (null for first page of set).
     *
     * @return string XML request
     */
    public function getHolding($idList, $maxItemsCount = null, $resumption = null)
    {
        // Build a list of the types of information we want to retrieve:
        $desiredParts = array(
            'Bibliographic Description',
            'Circulation Status',
            'Electronic Resource',
            'Hold Queue Length',
            'Item Description',
            'Item Use Restriction Type',
            'Location'
        );
        // Start the XML:
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . '<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ns1:version' . '="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"><ns1:LookupItemSet>';
        // Add the ID list:
        $i = -1;
        foreach ($idList as $agencyId => $id) {

            $agencyIdExt = '';
            if (++$i !== $agencyId)
                $agencyIdExt = '<ns1:Ext><ns1:AgencyId ns1:Scheme="http://www.niso.org/ncip/v1_0/schemes/agencyidtype/agencyidtype.scm">'.$agencyId.'</ns1:AgencyId></ns1:Ext>';
            // $id = str_replace("-", "", $id);
            $id = $this->cpkConvert($id);
            $xml .= '<ns1:BibliographicId>' . '<ns1:BibliographicItemId>' . '<ns1:BibliographicItemIdentifier>' . htmlspecialchars($id) . '</ns1:BibliographicItemIdentifier>' . '<ns1:BibliographicItemIdentifierCode ns1:Scheme="http://www.niso.org/ncip/v1_0/imp1/schemes/bibliographicitemidentifiercode/bibliographicitemidentifiercode.scm">Legal Deposit Number</ns1:BibliographicItemIdentifierCode>' . '</ns1:BibliographicItemId>' . $agencyIdExt . '</ns1:BibliographicId>';
        }
        // Add the desired data list:
        foreach ($desiredParts as $current) {
            $xml .= '<ns1:ItemElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/schemes/itemelementtype/itemelementtype.scm">' . htmlspecialchars($current) . '</ns1:ItemElementType>';
        }
        if (! empty($maxItemsCount)) {
            $xml .= '<ns1:MaximumItemsCount>' . htmlspecialchars($maxItemsCount) . '</ns1:MaximumItemsCount>';
        }
        // Add resumption token if necessary:
        if (! empty($resumption)) {
            $xml .= '<ns1:NextItemToken>' . htmlspecialchars($resumption) . '</ns1:NextItemToken>';
        }
        // Close the XML and send it to the caller:
        $xml .= '</ns1:LookupItemSet></ns1:NCIPMessage>';
        return $xml;
    }

    /**
     * Temporary method for dealing with item's location.
     *
     * @param string $itemID
     */
    public function getItemInfo($itemID)
    {
        $desiredParts = array(
            'Bibliographic Description',
            'Circulation Status',
            'Electronic Resource',
            'Hold Queue Length',
            'Item Description',
            'Item Use Restriction Type',
            'Location',
            'Physical Condition',
            'Security Marker',
            'Sensitization Flag'
        );

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . '<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ' . 'ns1:version="http://www.niso.org/schemas/ncip/v2_0/imp1/xsd/ncip_v2_0.xsd">' . '<ns1:LookupItem>' . '<ns1:ItemId><ns1:ItemIdentifierValue>' . htmlspecialchars($itemID) . '</ns1:ItemIdentifierValue></ns1:ItemId>';

        foreach ($desiredParts as $current) {
            $xml .= '<ns1:ItemElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/schemes/itemelementtype/itemelementtype.scm">' . htmlspecialchars($current) . '</ns1:ItemElementType>';
        }
        $xml .= '</ns1:LookupItem></ns1:NCIPMessage>';
        return $xml;
    }

    /**
     * Temporary method for dealing with item's location.
     *
     * @param string $itemID
     */
    public function getLocation($itemID)
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . '<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ' . 'ns1:version="http://www.niso.org/schemas/ncip/v2_0/imp1/xsd/ncip_v2_0.xsd">' . '<ns1:LookupItem>' . '<ns1:ItemId><ns1:ItemIdentifierValue>' . htmlspecialchars($itemID) . '</ns1:ItemIdentifierValue></ns1:ItemId>' . '<ns1:ItemElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/schemes/itemelementtype/itemelementtype.scm">' . 'Location</ns1:ItemElementType>' . '</ns1:LookupItem>' . '</ns1:NCIPMessage>';
    }

    /**
     * Build the NCIP request XML to get patron's fines.
     *
     * @param array $patron
     *            The patron array
     *
     * @return string NCIP request XML
     */
    public function getMyFines($patron)
    {
        $extras = array(
            '<ns1:UserFiscalAccountDesired/>'
        );
        return $this->getMyProfile($patron, $extras);
    }

    public function getMyHistory($patron)
    {
        // TODO
        return null;
    }

    /**
     * Build the NCIP request XML to get patron's current holds - books which
     * are reserved.
     *
     * @param array $patron
     *            The patron array
     *
     * @return string NCIP request XML
     */
    public function getMyHolds($patron)
    {
        $extras = array(
            '<ns1:RequestedItemsDesired/>'
        );
        return $this->getMyProfile($patron, $extras);
    }

    /**
     * Build the NCIP request XML to get patron's details.
     *
     * @param array $patron
     *            The patron array
     *
     * @return string NCIP request XML
     */
    public function getMyProfile($patron, $extras = null)
    {
        if ($extras == null) {
            $extras = array(
                '<ns1:UserElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/schemes/userelementtype/userelementtype.scm">Block Or Trap</ns1:UserElementType>' . '<ns1:UserElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/' . 'schemes/userelementtype/userelementtype.scm">' . 'Name Information' . '</ns1:UserElementType>' . '<ns1:UserElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/' . 'schemes/userelementtype/userelementtype.scm">' . 'User Address Information' . '</ns1:UserElementType>' . '<ns1:UserElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/' . 'schemes/userelementtype/userelementtype.scm">' . 'User Privilege' . '</ns1:UserElementType>'
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . '<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ' . 'ns1:version="http://www.niso.org/schemas/ncip/v2_0/imp1/xsd/ncip_v2_0.xsd">' . '<ns1:LookupUser>' . '<ns1:UserId>' . '<ns1:UserIdentifierValue>' . htmlspecialchars($patron['id']) . '</ns1:UserIdentifierValue>' . '</ns1:UserId>' . implode('', $extras) . '</ns1:LookupUser>' . '</ns1:NCIPMessage>';
    }

    /**
     * Build the NCIP request XML to get patron's current checked out items.
     *
     * @param array $patron
     *            The patron array
     *
     * @return string NCIP request XML
     */
    public function getMyTransactions($patron)
    {
        $extras = array(
            '<ns1:LoanedItemsDesired/>'
        );
        return $this->getMyProfile($patron, $extras);
    }

    /**
     * Build the request XML to log in a user.
     *
     * @param string $username
     *            Username for login
     * @param string $password
     *            Password for login
     * @param string $extras
     *            Extra elements to include in the request
     *
     * @return string NCIP request XML
     */
    public function patronLogin($username, $password)
    {
        $extras = array(
            '<ns1:UserElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/' . 'schemes/userelementtype/userelementtype.scm">' . 'Name Information' . '</ns1:UserElementType>' . '<ns1:UserElementType ns1:Scheme="http://www.niso.org/ncip/v1_0/' . 'schemes/userelementtype/userelementtype.scm">' . 'User Address Information' . '</ns1:UserElementType>'
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . '<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ' . 'ns1:version="http://www.niso.org/schemas/ncip/v2_0/imp1/' . 'xsd/ncip_v2_0.xsd">' . '<ns1:LookupUser>' . '<ns1:AuthenticationInput>' . '<ns1:AuthenticationInputData>' . htmlspecialchars($username) . '</ns1:AuthenticationInputData>' . '<ns1:AuthenticationDataFormatType>' . 'text' . '</ns1:AuthenticationDataFormatType>' . '<ns1:AuthenticationInputType>' . 'User Id' . '</ns1:AuthenticationInputType>' . '</ns1:AuthenticationInput>' . '<ns1:AuthenticationInput>' . '<ns1:AuthenticationInputData>' . htmlspecialchars($password) . '</ns1:AuthenticationInputData>' . '<ns1:AuthenticationDataFormatType>' . 'text' . '</ns1:AuthenticationDataFormatType>' . '<ns1:AuthenticationInputType>' . 'Password' . '</ns1:AuthenticationInputType>' . '</ns1:AuthenticationInput>' . implode('', $extras) . '</ns1:LookupUser>' . '</ns1:NCIPMessage>';
    }

    public function placeHold($holdDetails)
    {
        $id = $holdDetails['id'];
        // $id = substr_replace($id, '', 5, 1);
        $id .= '-';
        $id .= $holdDetails['item_id'];
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . '<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ns1:version' . '="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"><ns1:RequestItem>' . '<ns1:UserId>' . '<ns1:UserIdentifierValue>' . htmlspecialchars($holdDetails['patron']['id']) . '</ns1:UserIdentifierValue>' . '</ns1:UserId>' . '<ns1:ItemId>' . '<ns1:ItemIdentifierValue>' . htmlspecialchars($id) . '</ns1:ItemIdentifierValue>' . '</ns1:ItemId>' . '<ns1:RequestType ns1:Scheme="http://www.niso.org/ncip/v1_0/imp1/schemes/requesttype/requesttype.scm">Hold</ns1:RequestType>' . '<ns1:RequestScopeType ns1:Scheme="http://www.niso.org/ncip/v1_0/imp1/schemes/requestscopetype/requestscopetype.scm">Item</ns1:RequestScopeType>' . '<ns1:EarliestDateNeeded>2014-09-09T00:00:00</ns1:EarliestDateNeeded>' . '<ns1:NeedBeforeDate>2014-09-17T00:00:00</ns1:NeedBeforeDate>' . '<ns1:PickupLocation>' . $holdDetails['pickUpLocation'] . '</ns1:PickupLocation>' . '<ns1:PickupExpiryDate>2014-09-30T00:00:00</ns1:PickupExpiryDate>' . '</ns1:RequestItem></ns1:NCIPMessage>';
        return $xml;
    }

    /**
     * Build the NCIP request XML to renew patron's items.
     *
     * @param array $patron
     * @param string $item
     *
     * @return string NCIP request XML
     */
    public function renewMyItem($patron, $item)
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . '<ns1:NCIPMessage xmlns:ns1="http://www.niso.org/2008/ncip" ns1:version' . '="http://www.niso.org/schemas/ncip/v2_02/ncip_v2_02.xsd"><ns1:RenewItem>' . '<ns1:UserId>' . '<ns1:UserIdentifierValue>' . htmlspecialchars($patron['id']) . '</ns1:UserIdentifierValue>' . '</ns1:UserId>' . '<ns1:ItemId>' . '<ns1:ItemIdentifierValue>' . htmlspecialchars($item) . '</ns1:ItemIdentifierValue>' . '</ns1:ItemId>' . '</ns1:RenewItem></ns1:NCIPMessage>';
        return $xml;
    }
}
