<?php
/**
 * Mink favorites test class.
 *
 * PHP version 7
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
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Tests
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 */
namespace VuFindTest\Mink;

use Behat\Mink\Element\Element;

/**
 * Mink favorites test class.
 *
 * @category VuFind
 * @package  Tests
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org Main Page
 * @retry    4
 */
class FavoritesTest extends \VuFindTest\Integration\MinkTestCase
{
    use \VuFindTest\Feature\AutoRetryTrait;
    use \VuFindTest\Feature\LiveDatabaseTrait;
    use \VuFindTest\Feature\UserCreationTrait;

    /**
     * Standard setup method.
     *
     * @return void
     */
    public static function setUpBeforeClass(): void
    {
        static::failIfUsersExist();
    }

    /**
     * Standard setup method.
     *
     * @return void
     */
    public function setUp(): void
    {
        // Give up if we're not running in CI:
        if (!$this->continuousIntegrationRunning()) {
            $this->markTestSkipped('Continuous integration not running.');
            return;
        }
    }

    /**
     * Perform a search and return the page after submitting the form.
     *
     * @param string $query Search query to run
     *
     * @return Element
     */
    protected function gotoSearch($query = 'Dewey')
    {
        $session = $this->getMinkSession();
        $session->visit($this->getVuFindUrl() . '/Search/Home');
        $page = $session->getPage();
        $this->findCssAndSetValue($page, '#searchForm_lookfor', $query);
        $this->clickCss($page, '.btn.btn-primary');
        return $page;
    }

    /**
     * Perform a search and return the page after submitting the form and
     * clicking the first record.
     *
     * @param string $query Search query to run
     *
     * @return Element
     */
    protected function gotoRecord($query = 'Dewey')
    {
        $page = $this->gotoSearch($query);
        $this->clickCss($page, '.result a.title');
        return $page;
    }

    /**
     * Strip off the hash segment of a URL.
     *
     * @param string $url URL to strip
     *
     * @return string
     */
    protected function stripHash($url)
    {
        $parts = explode('#', $url);
        return $parts[0];
    }

    /**
     * Test adding a record to favorites (from the record page) while creating a
     * new account.
     *
     * @retryCallback tearDownAfterClass
     *
     * @return void
     */
    public function testAddRecordToFavoritesNewAccount()
    {
        $page = $this->gotoRecord();

        $this->clickCss($page, '.save-record');
        $this->clickCss($page, '.modal-body .createAccountLink');
        // Empty
        $this->snooze();
        $this->clickCss($page, '.modal-body .btn.btn-primary');

        // Invalid email
        $this->snooze();
        $this->fillInAccountForm($page, ['email' => 'blargasaurus']);

        $this->clickCss($page, '.modal-body .btn.btn-primary');
        // Correct
        $this->findCssAndSetValue($page, '#account_email', 'username1@ignore.com');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '#save_list');
        // Make list
        $this->clickCss($page, '#make-list');
        $this->snooze();
        // Empty
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCssAndSetValue($page, '#list_title', 'Test List');
        $this->findCssAndSetValue($page, '#list_desc', 'Just. THE BEST.');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->assertEquals(
            'Test List',
            $this->findCss($page, '#save_list option[selected]')->getHtml()
        );
        $this->findCssAndSetValue($page, '#add_mytags', 'test1 test2 "test 3"');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '.modal .alert.alert-success');
        $this->clickCss($page, '.modal-body .btn.btn-default');
        // Check list page
        $session = $this->getMinkSession();
        $recordURL = $this->stripHash($session->getCurrentUrl());
        $this->snooze();
        $this->clickCss($page, '.savedLists a');
        $this->snooze();
        $this->clickCss($page, '.resultItemLine1 a');
        $this->assertEquals($recordURL, $this->stripHash($session->getCurrentUrl()));
        $this->clickCss($page, '.logoutOptions a.logout');
    }

    /**
     * Test adding a record to favorites (from the record page) using an existing
     * account that is not yet logged in.
     *
     * @depends testAddRecordToFavoritesNewAccount
     *
     * @return void
     */
    public function testAddRecordToFavoritesLogin()
    {
        $page = $this->gotoRecord();

        $this->clickCss($page, '.save-record');
        // Login
        // - empty
        $this->submitLoginForm($page);
        $this->assertLightboxWarning($page, 'Login information cannot be blank.');
        // - wrong
        $this->fillInLoginForm($page, 'username1', 'superwrong');
        $this->submitLoginForm($page);
        $this->assertLightboxWarning($page, 'Invalid login -- please try again.');
        // - for real
        $this->fillInLoginForm($page, 'username1', 'test');
        $this->submitLoginForm($page);
        // Make sure we don't have Favorites because we have another populated list
        $this->assertNull($page->find('css', '.modal-body #save_list'));
        // Make Two Lists
        // - One for the next test
        $this->clickCss($page, '#make-list');
        $this->snooze();
        $this->findCssAndSetValue($page, '#list_title', 'Future List');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->assertEquals(
            'Future List',
            $this->findCss($page, '#save_list option[selected]')->getHtml()
        );
        // - One for now
        $this->clickCss($page, '#make-list');
        $this->snooze();
        $this->findCssAndSetValue($page, '#list_title', 'Login Test List');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->assertEquals(
            'Login Test List',
            $this->findCss($page, '#save_list option[selected]')->getHtml()
        );
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '.modal .alert.alert-success');
    }

    /**
     * Test adding a record to favorites (from the record page) using an existing
     * account that is already logged in.
     *
     * @depends testAddRecordToFavoritesNewAccount
     *
     * @return void
     */
    public function testAddRecordToFavoritesLoggedIn()
    {
        $page = $this->gotoRecord();
        // Login
        $this->clickCss($page, '#loginOptions a');
        $this->snooze();
        $this->fillInLoginForm($page, 'username1', 'test');
        $this->submitLoginForm($page);
        // Save Record
        $this->snooze();
        $this->clickCss($page, '.save-record');
        $this->snooze();
        $this->findCss($page, '#save_list');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '.modal .alert.alert-success');
    }

    /**
     * Test adding a record to favorites (from the search results) while creating a
     * new account.
     *
     * @retryCallback removeUsername2
     *
     * @return void
     */
    public function testAddSearchItemToFavoritesNewAccount()
    {
        $page = $this->gotoSearch();

        $this->clickCss($page, '.save-record');
        $this->clickCss($page, '.modal-body .createAccountLink');
        // Empty
        $this->snooze();
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->fillInAccountForm(
            $page, ['username' => 'username2', 'email' => 'blargasaurus']
        );
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->findCssAndSetValue($page, '#account_email', 'username2@ignore.com');
        // Test taken username
        $this->findCssAndSetValue($page, '#account_username', 'username1');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '#account_firstname');
        // Correct
        $this->fillInAccountForm(
            $page, ['username' => 'username2', 'email' => 'username2@ignore.com']
        );
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '#save_list');
        // Make list
        $this->clickCss($page, '#make-list');
        $this->snooze();
        // Empty
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCssAndSetValue($page, '#list_title', 'Test List');
        $this->findCssAndSetValue($page, '#list_desc', 'Just. THE BEST.');
        // Confirm that tags are disabled by default:
        $this->assertNull($page->find('css', '#list_tags'));
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->assertEquals(
            'Test List',
            $this->findCss($page, '#save_list option[selected]')->getHtml()
        );
        $this->findCssAndSetValue($page, '#add_mytags', 'test1 test2 "test 3"');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '.alert.alert-success');
        $this->clickCss($page, '.modal .close');
        // Check list page
        $this->snooze();
        $this->clickCss($page, '.result a.title');
        $this->snooze();
        $session = $this->getMinkSession();
        $recordURL = $session->getCurrentUrl();
        $this->clickCss($page, '.savedLists a');
        $this->snooze();
        $this->clickCss($page, '.resultItemLine1 a');
        $this->snooze();
        $this->assertEquals($recordURL, $session->getCurrentUrl());
        $this->clickCss($page, '.logoutOptions a.logout');
    }

    /**
     * Test adding a record to favorites (from the search results) using an existing
     * account that is not yet logged in.
     *
     * @depends testAddSearchItemToFavoritesNewAccount
     *
     * @return void
     */
    public function testAddSearchItemToFavoritesLogin()
    {
        $page = $this->gotoSearch();

        $this->clickCss($page, '.save-record');
        $this->snooze();
        // Login
        // - empty
        $this->submitLoginForm($page);
        $this->assertLightboxWarning($page, 'Login information cannot be blank.');
        // - for real
        $this->snooze();
        $this->fillInLoginForm($page, 'username2', 'test');
        $this->submitLoginForm($page);
        // Make sure we don't have Favorites because we have another populated list
        $this->assertNull($page->find('css', '.modal-body #save_list'));
        // Make Two Lists
        // - One for the next test
        $this->clickCss($page, '#make-list');
        $this->snooze();
        $this->findCssAndSetValue($page, '#list_title', 'Future List');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->assertEquals(
            'Future List',
            $this->findCss($page, '#save_list option[selected]')->getHtml()
        );
        // - One for now
        $this->clickCss($page, '#make-list');
        $this->snooze();
        $this->findCssAndSetValue($page, '#list_title', 'Login Test List');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->assertEquals(
            'Login Test List',
            $this->findCss($page, '#save_list option[selected]')->getHtml()
        );
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '.alert.alert-success');
    }

    /**
     * Test adding a record to favorites (from the search results) using an existing
     * account that is already logged in.
     *
     * @depends testAddSearchItemToFavoritesNewAccount
     *
     * @return void
     */
    public function testAddSearchItemToFavoritesLoggedIn()
    {
        $page = $this->gotoSearch();
        // Login
        $this->clickCss($page, '#loginOptions a');
        $this->snooze();
        $this->fillInLoginForm($page, 'username2', 'test');
        $this->submitLoginForm($page);
        // Count lists
        $listCount = count($page->findAll('css', '.savedLists a'));
        // Save Record
        $this->clickCss($page, '.save-record');
        $this->snooze();
        $this->findCss($page, '#save_list');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->findCss($page, '.alert.alert-success');
        // Test save status update on modal close
        $this->clickCss($page, '#modal .close');
        $this->snooze();
        $savedLists = $page->findAll('css', '.savedLists a');
        $this->assertEquals($listCount + 1, count($savedLists));
    }

    /**
     * Test that lists can be tagged when the optional setting is activated.
     *
     * @return void
     */
    public function testTaggedList()
    {
        $this->changeConfigs(
            ['config' =>
                [
                    'Social' => ['listTags' => 'enabled'],
                ],
            ]
        );
        $page = $this->gotoSearch('id:testbug2');

        // Login
        $this->clickCss($page, '.save-record');
        $this->snooze();
        $this->fillInLoginForm($page, 'username2', 'test');
        $this->submitLoginForm($page);

        $this->snooze();
        $this->findCss($page, '#save_list');
        // Make list
        $this->clickCss($page, '#make-list');
        $this->snooze();
        $this->findCssAndSetValue($page, '#list_title', 'Tagged List');
        $this->findCssAndSetValue($page, '#list_desc', 'It has tags on it!');
        $this->findCssAndSetValue($page, '#list_tags', 'These are "my list tags"');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->assertEquals(
            'Tagged List',
            $this->findCss($page, '#save_list option[selected]')->getHtml()
        );
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        $this->clickCss($page, '.alert.alert-success a');
        // Check list page
        $this->snooze();
        $this->assertEquals(
            'are, my list tags, these',
            $this->findCss($page, '.list-tags')->getHtml()
        );
    }

    /**
     * Login and go to account home
     *
     * @return \Behat\Mink\Element\DocumentElement
     */
    protected function setupBulkTest()
    {
        $this->changeConfigs(
            ['config' =>
                [
                    'Mail' => ['testOnly' => 1],
                ],
            ]
        );
        // Go home
        $session = $this->getMinkSession();
        $path = '/Search/Home';
        $session->visit($this->getVuFindUrl() . $path);
        $page = $session->getPage();
        // Login
        $this->clickCss($page, '#loginOptions a');
        $this->snooze();
        $this->fillInLoginForm($page, 'username1', 'test');
        $this->submitLoginForm($page);
        // Go to saved lists
        $path = '/MyResearch/Home';
        $session->visit($this->getVuFindUrl() . $path);
        return $page;
    }

    /**
     * Assert that the "no items were selected" message is visible in the cart
     * lightbox.
     *
     * @param Element $page Page element
     *
     * @return void
     */
    protected function checkForNonSelectedMessage(Element $page)
    {
        $warning = $this->findCss($page, '.modal-body .alert');
        $this->assertEquals(
            'No items were selected. Please click on a checkbox next to an item and try again.',
            $warning->getText()
        );
        $this->clickCss($page, '.modal .close');
        $this->snooze();
    }

    /**
     * Select all of the items currently in the cart lightbox.
     *
     * @param Element $page Page element
     *
     * @return void
     */
    protected function selectAllItemsInList(Element $page)
    {
        $selectAll = $this->findCss($page, '[name=bulkActionForm] .checkbox-select-all');
        $selectAll->check();
    }

    /**
     * Test that the email control works.
     *
     * @depends testAddRecordToFavoritesNewAccount
     *
     * @return void
     */
    public function testBulkEmail()
    {
        $page = $this->setupBulkTest();

        // First try clicking without selecting anything:
        $button = $this->findCss($page, '[name=bulkActionForm] [name=email]');
        $button->click();
        $this->snooze();
        $this->checkForNonSelectedMessage($page);

        // Now do it for real.
        $this->selectAllItemsInList($page);
        $button->click();
        $this->snooze();
        $this->findCssAndSetValue($page, '.modal #email_to', 'tester@vufind.org');
        $this->findCssAndSetValue($page, '.modal #email_from', 'asdf@vufind.org');
        $this->findCssAndSetValue($page, '.modal #email_message', 'message');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        // Check for confirmation message
        $this->assertEquals(
            'Your item(s) were emailed',
            $this->findCss($page, '.modal .alert-success')->getText()
        );
    }

    /**
     * Test that the export control works.
     *
     * @depends testAddRecordToFavoritesNewAccount
     *
     * @return void
     */
    public function testBulkExport()
    {
        $page = $this->setupBulkTest();

        // First try clicking without selecting anything:
        $button = $this->findCss($page, '[name=bulkActionForm] [name=export]');
        $button->click();
        $this->snooze();
        $this->checkForNonSelectedMessage($page);

        // Now do it for real -- we should get an export option list:
        $this->selectAllItemsInList($page);
        $button->click();
        $this->snooze();

        // Select EndNote option
        $select = $this->findCss($page, '#format');
        $select->selectOption('EndNote');

        // Do the export:
        $submit = $this->findCss($page, '.modal-body input[name=submit]');
        $submit->click();
        $this->snooze();
        $result = $this->findCss($page, '.modal-body .alert .text-center .btn');
        $this->assertEquals('Download File', $result->getText());
    }

    /**
     * Test that the print control works.
     *
     * @depends testAddRecordToFavoritesNewAccount
     *
     * @return void
     */
    public function testBulkPrint()
    {
        $session = $this->getMinkSession();
        $page = $this->setupBulkTest();

        // First try clicking without selecting anything:
        $button = $this->findCss($page, '[name=bulkActionForm] [name=print]');
        $button->click();
        $this->snooze();
        $warning = $this->findCss($page, '.flash-message');
        $this->assertEquals(
            'No items were selected. Please click on a checkbox next to an item and try again.',
            $warning->getText()
        );

        // Now do it for real -- we should get redirected.
        $this->selectAllItemsInList($page);
        $button->click();
        $this->snooze();
        list(, $params) = explode('?', $session->getCurrentUrl());
        $this->assertEquals('print=true', $params);
    }

    /**
     * Test that it is possible to email a public list.
     *
     * @depends testAddRecordToFavoritesNewAccount
     *
     * @return void
     */
    public function testEmailPublicList()
    {
        $page = $this->setupBulkTest();

        // Click on the first list and make it public:
        $link = $this->findAndAssertLink($page, 'Test List');
        $link->click();
        $this->snooze();
        $button = $this->findAndAssertLink($page, 'Edit List');
        $button->click();
        $this->snooze();
        $this->clickCss($page, '#list_public_1'); // radio button
        $this->clickCss($page, 'input[name="submit"]'); // submit button
        $this->snooze();

        // Now log out:
        $this->clickCss($page, '.logoutOptions a.logout');
        $this->snooze();

        // Now try to email the list:
        $this->selectAllItemsInList($page);
        $this->findCss($page, '[name=bulkActionForm] [name=email]')
            ->click();
        $this->snooze();

        // Log in as different user:
        $this->fillInLoginForm($page, 'username2', 'test');
        $this->submitLoginForm($page);

        // Send the email:
        $this->findCssAndSetValue($page, '.modal #email_to', 'tester@vufind.org');
        $this->findCssAndSetValue($page, '.modal #email_from', 'asdf@vufind.org');
        $this->findCssAndSetValue($page, '.modal #email_message', 'message');
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        // Check for confirmation message
        $this->assertEquals(
            'Your item(s) were emailed',
            $this->findCss($page, '.modal .alert-success')->getText()
        );
    }

    /**
     * Test that the bulk delete control works.
     *
     * @depends testAddRecordToFavoritesNewAccount
     *
     * @return void
     */
    public function testBulkDelete()
    {
        $page = $this->setupBulkTest();

        // First try clicking without selecting anything:
        $button = $this->findCss($page, '[name=bulkActionForm] [name=delete]');
        $button->click();
        $this->snooze();
        $this->checkForNonSelectedMessage($page);

        // Now do it for real -- we should get redirected.
        $this->selectAllItemsInList($page);
        $button->click();
        $this->snooze();
        $this->clickCss($page, '.modal-body .btn.btn-primary');
        $this->snooze();
        // Check for confirmation message
        $this->assertEquals(
            'Your favorite(s) were deleted.',
            $this->findCss($page, '.modal .alert-success')->getText()
        );
        $this->clickCss($page, '.modal .close');
        $this->snooze();
        $this->assertFalse(is_object($page->find('css', '.result')));
    }

    /**
     * Retry cleanup method in case of failure during
     * testAddSearchItemToFavoritesNewAccount.
     *
     * @return void
     */
    protected function removeUsername2()
    {
        static::removeUsers(['username2']);
    }

    /**
     * Standard teardown method.
     *
     * @return void
     */
    public static function tearDownAfterClass(): void
    {
        static::removeUsers(['username1', 'username2']);
    }
}
