<?php
/*
 * Copyright (C) 2015, Siemens AG
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
namespace Fossology\SpdxTwo;

use Fossology\Lib\Agent\Agent;
use Fossology\Lib\BusinessRules\LicenseMap;
use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Dao\CopyrightDao;
use Fossology\Lib\Dao\TreeDao;
use Fossology\Lib\Dao\UploadDao;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\DecisionTypes;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Data\Upload\Upload;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Proxy\LicenseViewProxy;
use Fossology\Lib\Proxy\ScanJobProxy;

include_once(__DIR__ . "/version.php");
include_once(__DIR__ . "/services.php");

class SpdxTwoAgent extends Agent
{

  const OUTPUT_FORMAT_KEY = "outputFormat";
  const DEFAULT_OUTPUT_FORMAT = "spdx2";
  const AVAILABLE_OUTPUT_FORMATS = "spdx2,spdx2tv,dep5";
  const UPLOAD_ADDS = "uploadsAdd";

  /** @var UploadDao */
  private $uploadDao;
  /** @var ClearingDao */
  private $clearingDao;
  /** @var DbManager */
  protected $dbManager;
  /** @var Twig_Environment */
  protected $renderer;
  /** @var LicenseMap */
  private $licenseMap;
  /** @var array */
  protected $agentNames = array('nomos' => 'N', 'monk' => 'M');
  /** @var array */
  protected $includedLicenseIds = array();
  /** @var string */
  protected $uri;
  /** @var string */
  protected $outputFormat = self::DEFAULT_OUTPUT_FORMAT;

  function __construct()
  {
    parent::__construct('spdx2', AGENT_VERSION, AGENT_REV);

    $this->uploadDao = $this->container->get('dao.upload');
    $this->clearingDao = $this->container->get('dao.clearing');
    $this->dbManager = $this->container->get('db.manager');
    $this->renderer = $this->container->get('twig.environment');
    $this->renderer->setCache(false);

    $this->agentSpecifLongOptions[] = self::UPLOAD_ADDS.':';
    $this->agentSpecifLongOptions[] = self::OUTPUT_FORMAT_KEY.':';
  }

  /**
   * @param string[] $args
   * @param string $key1
   * @param string $key2
   *
   * @return string[] $args
   */
  protected function preWorkOnArgsFlp($args,$key1,$key2)
  {
    $needle = ' --'.$key2.'=';
    if (strpos($args[$key1],$needle) !== false) {
      $exploded = explode($needle,$args[$key1]);
      $args[$key1] = trim($exploded[0]);
      $args[$key2] = trim($exploded[1]);
    }
    return $args;
  }

  /**
   * @param string[] $args
   *
   * @return string[] $args
   */
  protected function preWorkOnArgs($args)
  {
    if ((!array_key_exists(self::OUTPUT_FORMAT_KEY,$args)
         || $args[self::OUTPUT_FORMAT_KEY] === "")
        && array_key_exists(self::UPLOAD_ADDS,$args))
    {
      $args = $this->preWorkOnArgsFlp($args,self::UPLOAD_ADDS,self::OUTPUT_FORMAT_KEY);
    }
    else
    {
      if (!array_key_exists(self::UPLOAD_ADDS,$args) || $args[self::UPLOAD_ADDS] === "")
      {
        $args = $this->preWorkOnArgsFlp($args,self::UPLOAD_ADDS,self::OUTPUT_FORMAT_KEY);
      }
    }
    return $args;
  }

  function processUploadId($uploadId)
  {
    $args = $this->preWorkOnArgs($this->args);

    if(array_key_exists(self::OUTPUT_FORMAT_KEY,$args))
    {
      $possibleOutputFormat = trim($args[self::OUTPUT_FORMAT_KEY]);
      if(in_array($possibleOutputFormat, explode(',',self::AVAILABLE_OUTPUT_FORMATS)))
      {
        $this->outputFormat = $possibleOutputFormat;
      }
    }
    $this->licenseMap = new LicenseMap($this->dbManager, $this->groupId, LicenseMap::REPORT, true);
    $this->computeUri($uploadId);

    $packageNodes = $this->renderPackage($uploadId);
    $additionalUploadIds = array_key_exists(self::UPLOAD_ADDS,$args) ? explode(',',$args[self::UPLOAD_ADDS]) : array();
    $packageIds = array($uploadId);
    foreach($additionalUploadIds as $additionalId)
    {
      $packageNodes .= $this->renderPackage($additionalId);
      $packageIds[] = $additionalId;
    }

    $this->writeReport($packageNodes, $packageIds, $uploadId);
    return true;
  }

  protected function getTemplateFile($partname)
  {
    $prefix = $this->outputFormat . "-";
    $postfix = ".twig";
    switch ($this->outputFormat)
    {
      case "spdx2":
        $postfix = ".xml" . $postfix;
        break;
      case "spdx2tv":
        break;
      case "dep5":
        $prefix = $prefix . "copyright-";
        break;
    }
    return $prefix . $partname . $postfix;
  }

  protected function getUri($fileBase,$packageName)
  {
    $fileName = $fileBase. strtoupper($this->outputFormat)."_".$packageName.'_'.time();
    switch ($this->outputFormat)
    {
      case "spdx2":
        $fileName = $fileName .".rdf" ;
        break;
      case "spdx2tv":
        $fileName = $fileName .".spdx" ;
        break;
      case "dep5":
        $fileName = $fileName .".txt" ;
        break;
    }
    return $fileName;
  }

  protected function renderPackage($uploadId)
  {
    $uploadTreeTableName = $this->uploadDao->getUploadtreeTableName($uploadId);
    $itemTreeBounds = $this->uploadDao->getParentItemBounds($uploadId,$uploadTreeTableName);
    $clearingDecisions = $this->clearingDao->getFileClearingsFolder($itemTreeBounds, $this->groupId);
    $this->heartbeat(0);
    $filesWithLicenses = $this->getFilesWithLicensesFromClearings($clearingDecisions);

    $licenseComment = $this->addScannerResults($filesWithLicenses, $itemTreeBounds);
    $this->addCopyrightResults($filesWithLicenses, $uploadId);
    $this->heartbeat(0);

    $upload = $this->uploadDao->getUpload($uploadId);
    $fileNodes = $this->generateFileNodes($filesWithLicenses, $upload->getTreeTableName());

    $mainLicenseIds = $this->clearingDao->getMainLicenseIds($uploadId, $this->groupId);
    $mainLicenses = array();
    foreach($mainLicenseIds as $licId)
    {
      $reportedLicenseId = $this->licenseMap->getProjectedId($licId);
      $this->includedLicenseIds[$reportedLicenseId] = $reportedLicenseId;
      $mainLicenses[] = $this->licenseMap->getProjectedShortname($reportedLicenseId);
    }

    $hashes = $this->uploadDao->getUploadHashes($uploadId);
    return $this->renderString($this->getTemplateFile('package'),array(
        'uploadId'=>$uploadId,
        'uri'=>$this->uri,
        'packageName'=>$upload->getFilename(),
        'uploadName'=>$upload->getFilename(),
        'sha1'=>$hashes['sha1'],
        'md5'=>$hashes['md5'],
        'verificationCode'=>$this->getVerificationCode($upload),
        'mainLicenses'=>$mainLicenses,
        'licenseComments'=>$licenseComment,
        'fileNodes'=>$fileNodes)
            );
  }

  /**
   * @param ClearingDecision[] $clearingDecisions
   * @return string[][][] $filesWithLicenses mapping item->'concluded'->(array of shortnames)
   */
  protected function getFilesWithLicensesFromClearings(&$clearingDecisions)
  {
    $filesWithLicenses = array();
    $clearingsProceeded = 0;
    foreach ($clearingDecisions as $clearingDecision) {
      $clearingsProceeded += 1;
      if(($clearingsProceeded&2047)==0)
      {
        $this->heartbeat(0);
      }
      if($clearingDecision->getType() == DecisionTypes::IRRELEVANT)
      {
        continue;
      }

      foreach ($clearingDecision->getClearingLicenses() as $clearingLicense) {
        if ($clearingLicense->isRemoved())
        {
          continue;
        }
        $reportedLicenseId = $this->licenseMap->getProjectedId($clearingLicense->getLicenseId());
        $this->includedLicenseIds[$reportedLicenseId] = $reportedLicenseId;
        $filesWithLicenses[$clearingDecision->getUploadTreeId()]['concluded'][] =
                                                 $this->licenseMap->getProjectedShortname($reportedLicenseId);
      }
    }
    return $filesWithLicenses;
  }

  /**
   * @param string[][][] $filesWithLicenses
   * @param string[] $licenses
   * @param string[] $copyrights
   * @param string $file
   * @param string $fullPath
   */
  protected function toLicensesWithFilesAdder(&$filesWithLicenses, $licenses, $copyrights, $file, $fullPath)
  {
    sort($licenses);
    $key = implode(" or ", $licenses);

    if (!array_key_exists($key, $filesWithLicenses))
    {
      $filesWithLicenses[$key]['files']=array();
      $filesWithLicenses[$key]['copyrights']=array();
    }

    $filesWithLicenses[$key]['files'][$file] = $fullPath;
    foreach ($copyrights as $copyright) {
      if (!in_array($copyright, $filesWithLicenses[$key]['copyrights'])) {
        $filesWithLicenses[$key]['copyrights'][] = $copyright;
      }
    }
  }

  /**
   * @param string[][][] $filesWithLicenses
   * @param string $treeTableName
   */
  protected function toLicensesWithFiles(&$filesWithLicenses, $treeTableName)
  {
    $licensesWithFiles = array();
    $treeDao = $this->container->get('dao.tree');
    $filesProceeded = 0;
    foreach($filesWithLicenses as $fileId=>$licenses)
    {
      $filesProceeded += 1;
      if(($filesProceeded&2047)==0)
      {
        $this->heartbeat(0);
      }
      $fullPath = $treeDao->getFullPath($fileId,$treeTableName);
      if(!empty($licenses['concluded']) && count($licenses['concluded'])>0)
      {
        $this->toLicensesWithFilesAdder($licensesWithFiles,$licenses['concluded'],$licenses['copyrights'],$fileId,$fullPath);
      }
      elseif(!empty($licenses['scanner']))
      {
        $msgLicense = "NoLicenseConcluded (scanners found: " . implode(' or ',$licenses['scanner']). ")";
        $this->toLicensesWithFilesAdder($licensesWithFiles,array($msgLicense),$licenses['copyrights'],$fileId,$fullPath);
      }
      else
      {
        $msgLicense = "NoLicenseFound";
        $this->toLicensesWithFilesAdder($licensesWithFiles,array($msgLicense),$licenses['copyrights'],$fileId,$fullPath);
      }
    }
    return $licensesWithFiles;
  }

  /**
   * @param string[][][] $filesWithLicenses
   * @param ItemTreeBounds $itemTreeBounds
   */
  protected function addScannerResults(&$filesWithLicenses, $itemTreeBounds)
  {
    $uploadId = $itemTreeBounds->getUploadId();
    $scannerAgents = array_keys($this->agentNames);
    $scanJobProxy = new ScanJobProxy($this->container->get('dao.agent'), $uploadId);
    $scanJobProxy->createAgentStatus($scannerAgents);
    $scannerIds = $scanJobProxy->getLatestSuccessfulAgentIds();
    if(empty($scannerIds))
    {
      return;
    }
    $selectedScanners = '{'.implode(',',$scannerIds).'}';
    $tableName = $itemTreeBounds->getUploadTreeTableName();
    $stmt = __METHOD__ .'.scanner_findings';
    $sql = "SELECT uploadtree_pk,rf_fk FROM $tableName ut, license_file
      WHERE ut.pfile_fk=license_file.pfile_fk AND rf_fk IS NOT NULL AND agent_fk=any($1)";
    $param = array($selectedScanners);
    if ($tableName == 'uploadtree_a') {
      $param[] = $uploadId;
      $sql .= " AND upload_fk=$".count($param);
      $stmt .= $tableName;
    }
    $sql .=  " GROUP BY uploadtree_pk,rf_fk";
    $this->dbManager->prepare($stmt, $sql);
    $res = $this->dbManager->execute($stmt,$param);
    while($row=$this->dbManager->fetchArray($res))
    {
      $reportedLicenseId = $this->licenseMap->getProjectedId($row['rf_fk']);
      $shortName = $this->licenseMap->getProjectedShortname($reportedLicenseId);
      if ($shortName != 'No_license_found' && $shortName != 'Void') {
        $filesWithLicenses[$row['uploadtree_pk']]['scanner'][] = $shortName;
        $this->includedLicenseIds[$reportedLicenseId] = $reportedLicenseId;
      }
    }
    $this->dbManager->freeResult($res);
    return "licenseInfoInFile determined by Scanners $selectedScanners";
  }

  protected function addCopyrightResults(&$filesWithLicenses, $uploadId)
  {
    /* @var $copyrightDao CopyrightDao */
    $copyrightDao = $this->container->get('dao.copyright');
    $uploadtreeTable = $this->uploadDao->getUploadtreeTableName($uploadId);
    $allEntries = $copyrightDao->getAllEntries('copyright', $uploadId, $uploadtreeTable, $type='skipcontent'); //, $onlyCleared=true, DecisionTypes::IDENTIFIED, 'textfinding!=\'\'');
    foreach ($allEntries as $finding) {
      $filesWithLicenses[$finding['uploadtree_pk']]['copyrights'][] = \convertToUTF8($finding['content']);
    }
  }

  protected function computeUri($uploadId)
  {
    global $SysConf;
    $upload = $this->uploadDao->getUpload($uploadId);
    $packageName = $upload->getFilename();

    $fileBase = $SysConf['FOSSOLOGY']['path']."/report/";

    $this->uri = $this->getUri($fileBase,$packageName);
  }

  protected function writeReport($packageNodes, $packageIds, $uploadId)
  {
    $fileBase = dirname($this->uri);

    if(!is_dir($fileBase)) {
      mkdir($fileBase, 0777, true);
    }
    umask(0133);

    $message = $this->renderString($this->getTemplateFile('document'),array(
        'documentName'=>$fileBase,
        'uri'=>$this->uri,
        'userName'=>$this->container->get('dao.user')->getUserName($this->userId),
        'organisation'=>'',
        'packageNodes'=>$packageNodes,
        'packageIds'=>$packageIds,
        'licenseTexts'=>$this->getLicenseTexts())
            );

    // To ensure the file is valid, replace any non-printable characters with a question mark.
    // 'Non-printable' is ASCII < 0x20 (excluding \r, \n and tab) and 0x7F (delete).
    $message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/','?',$message);

    file_put_contents($this->uri, $message);
    $this->updateReportTable($uploadId, $this->jobId, $this->uri);
  }

  protected function updateReportTable($uploadId, $jobId, $fileName){
    $this->dbManager->insertTableRow('reportgen',
            array('upload_fk'=>$uploadId, 'job_fk'=>$jobId, 'filepath'=>$fileName),
            __METHOD__);
  }

  /**
   * @param string $templateName
   * @param array $vars
   * @return string
   */
  protected function renderString($templateName, $vars)
  {
    return $this->renderer->loadTemplate($templateName)->render($vars);
  }

  protected function generateFileNodes($filesWithLicenses, $treeTableName)
  {
    if (strcmp($this->outputFormat, "dep5")!==0)
    {
      return $this->generateFileNodesByFiles($filesWithLicenses, $treeTableName);
    }
    else
    {
      return $this->generateFileNodesByLicenses($filesWithLicenses, $treeTableName);
    }
  }

  protected function generateFileNodesByFiles($filesWithLicenses, $treeTableName)
  {
    /* @var $treeDao TreeDao */
    $treeDao = $this->container->get('dao.tree');
    
    $filesProceeded = 0;
    $lastValue = 0;
    $content = '';
    foreach($filesWithLicenses as $fileId=>$licenses)
    {
      $filesProceeded += 1;
      if(($filesProceeded&2047)==0)
      {
        $this->heartbeat($filesProceeded - $lastValue);
        $lastValue = $filesProceeded;
      }
      $hashes = $treeDao->getItemHashes($fileId);
      $fileName = $treeDao->getFullPath($fileId,$treeTableName);
      $content .= $this->renderString($this->getTemplateFile('file'),array(
          'fileId'=>$fileId,
          'sha1'=>$hashes['sha1'],
          'md5'=>$hashes['md5'],
          'uri'=>$this->uri,
          'fileName'=>$fileName,
          'fileDirName'=>dirname($fileName),
          'fileBaseName'=>basename($fileName),
          'concludedLicenses'=>$licenses['concluded'],
          'scannerLicenses'=>$licenses['scanner'],
          'copyrights'=>$licenses['copyrights']));
    }
        $this->heartbeat($filesProceeded - $lastValue);
    return $content;
  }

  protected function generateFileNodesByLicenses($filesWithLicenses, $treeTableName)
  {
    $licensesWithFiles = $this->toLicensesWithFiles($filesWithLicenses, $treeTableName);

    $content = '';
    $filesProceeded = 0;
    $lastStep = 0;
    $lastValue = 0;
    foreach($licensesWithFiles as $licenseId=>$entry)
    {
      $filesProceeded += count($entry['files']);
      if($filesProceeded&(~2047) > $lastStep)
      {
        $this->heartbeat($filesProceeded - $lastValue);
        $lastStep = $filesProceeded&(~2047) + 2048;
        $lastValue = $filesProceeded;
      }

      $comment = "";
      if (strrpos($licenseId, "NoLicenseConcluded (scanners found: ", -strlen($licenseId)) !== false) {
        $comment = substr($licenseId,20,strlen($licenseId)-21);
        $licenseId = "NoLicenseConcluded";
      }

      $content .= $this->renderString($this->getTemplateFile('file'),array(
          'fileNames'=>$entry['files'],
          'license'=>$licenseId,
          'copyrights'=>$entry['copyrights'],
          'comment'=>$comment));
    }
    $this->heartbeat($filesProceeded - $lastValue);
    return $content;
  }

  /**
   * @return string[] with keys being shortname
   */
  protected function getLicenseTexts() {
    $licenseTexts = array();
    $licenseViewProxy = new LicenseViewProxy($this->groupId,array(LicenseViewProxy::OPT_COLUMNS=>array('rf_pk','rf_shortname','rf_text')));
    $this->dbManager->prepare($stmt=__METHOD__, $licenseViewProxy->getDbViewQuery());
    $res = $this->dbManager->execute($stmt);
    while($row=$this->dbManager->fetchArray($res))
    {
      if (array_key_exists($row['rf_pk'], $this->includedLicenseIds)) {
        $licenseTexts[$row['rf_shortname']] = $row['rf_text'];
      }
    }
    $this->dbManager->freeResult($res);
    return $licenseTexts;
  }

  /**
   * @param UploadTree $upload
   * @return string
   */
  protected function getVerificationCode(Upload $upload)
  {
    $stmt = __METHOD__;
    $param = array();
    if ($upload->getTreeTableName()=='uploadtree_a')
    {
      $sql = $upload->getTreeTableName().' WHERE upload_fk=$1 AND';
      $param[] = $upload->getId();
    }
    else
    {
      $sql = $upload->getTreeTableName().' WHERE';
      $stmt .= '.'.$upload->getTreeTableName();
    }

    $sql = "SELECT STRING_AGG(lower_sha1,'') concat_sha1 FROM
       (SELECT LOWER(pfile_sha1) lower_sha1 FROM pfile, $sql pfile_fk=pfile_pk ORDER BY pfile_sha1) templist";
    $filelistPack = $this->dbManager->getSingleRow($sql,$param,$stmt);

    return sha1($filelistPack['concat_sha1']);
  }

}

$agent = new SpdxTwoAgent();
$agent->scheduler_connect();
$agent->run_scheduler_event_loop();
$agent->scheduler_disconnect(0);
