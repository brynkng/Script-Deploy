<?php
/**
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information please see
 * <http://phing.info>.
 */

require_once 'phing/Task.php';
require_once 'phing/tasks/ext/scriptdeploy/DbSyntaxFactory.php';


/**
 * Execute php scripts that have not been applied yet using a db changelog.
 *
 * This task reuses the database checking functionality of dbdeploy.
 *
 * <scriptdeploy url="mysql:host=localhost;dbname=test"
 *     userid="scriptdeploy" password="scriptdeploy" dir="db">
 *
 * script_changelog table required with the following schema:
 * change_number BIGINT(20) PRIMARY KEY NOT NULL,
 * delta_set VARCHAR(10) NOT NULL,
 * start_dt TIMESTAMP NOT NULL,
 * complete_dt TIMESTAMP NULL,
 * applied_by VARCHAR(100) NOT NULL,
 * description VARCHAR(500) NOT NULL
 *
 * @author   Bryan King at ReminderMedia (http://remindermedia.com)
 * @package  phing.tasks.ext.scriptdeploy
 * @version  $Revision: 1
 */
class ScriptDeployTask extends Task
{
    /**
     * The tablename to use from the database for storing all changes
     * This cannot be changed
     *
     * @var string
     */
    public static $TABLE_NAME = 'script_changelog';

    /**
     * Connection string for the database connection
     *
     * @var string
     */
    private $_url;

    /**
     * The userid for the database connection
     *
     * @var string
     */
    private $_userId;

    /**
     * The password of the database user
     *
     * @var string
     */
    private $_password;

    /**
     * Path to the directory that holds the database script files
     *
     * @var string
     */
    private $_dir;

    /**
     * The deltaset that's being used
     *
     * @var string
     */
    private $_deltaSet = 'Main';

    /**
     * Contains the object for the DBMS that is used
     *
     * @var object
     */
    private $_dbmsSyntax = null;

    /**
     * Array with all change numbers that are applied already
     *
     * @var array
     */
    private $_appliedChangeNumbers = array();

    /**
     * Checkall attribute
     * False means scriptdeploy will only apply scripts that have a higher change number
     * than the last change number that was applied.
     * True means scriptdeploy will apply all changes that aren't applied
     * already (in ascending order).
     *
     * @var int
     */
    private $_checkall = false;

    /**
     * @var PDO
     */
    private $_dbHandle;

    private $_scriptsToBeApplied;

    /**
     * Custom command to be applied to each script. If not set scripts will be run using
     * default php command
     *
     * @var string
     */
    private $_customCommand = false;

    /**
     * The main function for the task
     *
     * @throws BuildException
     * @return void
     */
    public function main()
    {
        try {
            $this->_setupDb();

            $this->_determineRevisionsAlreadyInDb();

            $this->_determineScriptsToBeApplied();

            if ($this->_checkall) {
                $this->log(
                    'Applying all scripts regardless of delta number order.'
                );
            }

            $this->deploy();

        } catch (Exception $e) {
            throw new BuildException($e);
        }

    }

    /**
     * Get the numbers of all the scripts that are already applied according to
     * the changelog table in the database
     *
     * @return array
     */
    protected function getAppliedChangeNumbers()
    {
        if (count($this->_appliedChangeNumbers) == 0) {
            $appliedChangeNumbers = array();

            $sql = "SELECT *
                    FROM " . self::$TABLE_NAME . "
                    WHERE
                        delta_set = '$this->_deltaSet'
                        AND complete_dt IS NOT NULL
                    ORDER BY change_number";
            foreach ($this->_dbHandle->query($sql) as $change) {
                $appliedChangeNumbers[] = $change['change_number'];
            }
            $this->_appliedChangeNumbers = $appliedChangeNumbers;
        }
        return $this->_appliedChangeNumbers;
    }

    /**
     * Get the number of the last applied script
     *
     * @return int|mixed The highest change number that was applied
     */
    protected function getLastChangeAppliedInDb()
    {
        return (count($this->_appliedChangeNumbers) > 0)
                ? max($this->_appliedChangeNumbers) : 0;
    }

    /**
     * Execute unapplied scripts
     *
     * @return void
     */
    protected function deploy()
    {
        foreach ($this->_scriptsToBeApplied as $fileChangeNumber => $fileName) {

            $this->_logDeployChangeStarted($fileChangeNumber, $fileName);

            $this->log("Applying #{$fileChangeNumber}: {$fileName}...");
            $command = $this->_getCommand();
            exec($command . ' ' . realpath($this->_dir) . '/' . $fileName, $output = null, $exitCode);

            if ($exitCode === 0) {
                $this->_logDeployChangeCompleted($fileChangeNumber);
            } else {
                $this->log("Script #$fileChangeNumber was not applied because it failed with code: $exitCode");
            }
        }
    }

    /**
     * Get a list of all the scripts in the scripts directory
     *
     * @return array
     */
    protected function getDeltasFilesArray()
    {
        $files = array();
        if (!is_dir($this->_dir)) {
            throw new Exception('Script directory does not exist!');
        }
        $baseDir = realpath($this->_dir);
        $dh = opendir($baseDir);
        $fileChangeNumberPrefix = '';
        while (($file = readdir($dh)) !== false) {
            if (preg_match('[\d+]', $file, $fileChangeNumberPrefix)) {
                $files[intval($fileChangeNumberPrefix[0])] = $file;
            }
        }
        return $files;
    }

    /**
     * Sort files in the patch files directory (ascending or descending depending on $undo boolean)
     *
     * @param array $files
     * @return void
     */
    protected function sortFiles(&$files)
    {
        ksort($files);
    }

    /**
     * Determine if this patch file need to be deployed
     * (using fileChangeNumber, lastChangeAppliedInDb and $this->checkall)
     *
     * @param int $fileChangeNumber
     * @param string $lastChangeAppliedInDb
     * @return bool True or false if patch file needs to be deployed
     */
    protected function fileNeedsToBeRead($fileChangeNumber, $lastChangeAppliedInDb)
    {
        if ($this->_checkall) {
            return (!in_array($fileChangeNumber, $this->_appliedChangeNumbers));
        } else {
            return ($fileChangeNumber > $lastChangeAppliedInDb);
        }
    }

    /**
     * Set the url for the database connection
     *
     * @param string $url
     * @return void
     */
    public function setUrl($url)
    {
        $this->_url = $url;
    }

    /**
     * Set the userid for the database connection
     *
     * @param string $userId
     * @return void
     */
    public function setUserId($userId)
    {
        $this->_userId = $userId;
    }

    /**
     * Set the password for the database connection
     *
     * @param string $password
     * @return void
     */
    public function setPassword($password)
    {
        $this->_password = $password;
    }

    /**
     * Set the directory where to find the scripts
     *
     * @param string $dir
     * @return void
     */
    public function setDir($dir)
    {
        $this->_dir = $dir;
    }

    /**
     * Set the deltaset property
     *
     * @param string $deltaSet
     * @return void
     */
    public function setDeltaSet($deltaSet)
    {
        $this->_deltaSet = $deltaSet;
    }

    /**
     * Set the checkall property
     *
     * @param bool $checkall
     * @return void
     */
    public function setCheckAll($checkall)
    {
        $this->_checkall = (int)$checkall;
    }

    public function setCustomCommand($customCommand)
    {
        $this->_customCommand = $customCommand;
    }

    private function _logDeployChangeStarted($fileChangeNumber, $fileName)
    {
        $sql = 'INSERT INTO ' . self::$TABLE_NAME . '
                    (change_number, delta_set, start_dt, applied_by, description)' .
                ' VALUES (' . $fileChangeNumber . ', \'' . $this->_deltaSet . '\', ' .
                    $this->_dbmsSyntax->generateTimestamp() .
                    ', \'scriptdeploy\', \'' . $fileName . '\');' . "\n";

        try {
            $this->_dbHandle->query($sql);
        } catch(Exception $E) {
            //entry already existed for change number. It must have failed before, so let's try again
            $sql = 'UPDATE ' . self::$TABLE_NAME . '
                         SET start_dt = ' . $this->_dbmsSyntax->generateTimestamp() . '
                         WHERE change_number = ' . $fileChangeNumber . '
                         AND delta_set = \'' . $this->_deltaSet . '\';' . "\n";
            $this->_dbHandle->query($sql);
        }
    }

    private function _logDeployChangeCompleted($fileChangeNumber)
    {
        $sql = 'UPDATE ' . self::$TABLE_NAME . '
                         SET complete_dt = ' . $this->_dbmsSyntax->generateTimestamp() . '
                         WHERE change_number = ' . $fileChangeNumber . '
                         AND delta_set = \'' . $this->_deltaSet . '\';' . "\n";
        $this->_dbHandle->query($sql);
    }

    private function _getScriptsToBeApplied()
    {
        $lastChangeAppliedInDb = $this->getLastChangeAppliedInDb();
        $files = $this->getDeltasFilesArray();
        $this->sortFiles($files);
        $scriptsToBeApplied = array();
        foreach ($files as $fileChangeNumber => $fileName) {
            if ($this->fileNeedsToBeRead($fileChangeNumber, $lastChangeAppliedInDb)) {
                $scriptsToBeApplied[$fileChangeNumber] = $fileName;
            }
        }

        return $scriptsToBeApplied;
    }

    private function _setupDb()
    {
        $dbms = substr($this->_url, 0, strpos($this->_url, ':'));
        $dbSyntaxFactory = new DbSyntaxFactory($dbms);
        $this->_dbmsSyntax = $dbSyntaxFactory->getDbmsSyntax();
        $this->_dbHandle = new PDO($this->_url, $this->_userId, $this->_password);
        $this->_dbHandle->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function _determineRevisionsAlreadyInDb()
    {
        $this->_appliedChangeNumbers = $this->getAppliedChangeNumbers();
        $this->log('Current script revision: ' . $this->getLastChangeAppliedInDb());
    }

    private function _determineScriptsToBeApplied()
    {
        $this->_scriptsToBeApplied = $this->_getScriptsToBeApplied();
        $changeNumbersToBeApplied = join(', ', array_keys($this->_scriptsToBeApplied));

        if (!empty($changeNumbersToBeApplied)) {
            $toBeApplied = $changeNumbersToBeApplied;
        } else {
            $toBeApplied = '(None)';
        }

        $this->log("To be applied:\n" . $toBeApplied);
    }
    
    private function _getCommand()
    {
        if ($this->_customCommand) {
            $command = $this->_customCommand;
        } else {
            $command = 'php';
        }

        return $command;
    }
}
