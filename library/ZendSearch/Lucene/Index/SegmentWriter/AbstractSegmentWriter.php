<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/zf2 for the canonical source repository
 * @copyright Copyright (c) 2005-2012 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 * @package   Zend_Search
 */

namespace ZendSearch\Lucene\Index\SegmentWriter;

use ZendSearch\Lucene\Document;
use ZendSearch\Lucene\Index;
use ZendSearch\Lucene\Storage\Directory;
use ZendSearch\Lucene\Storage\File;

/**
 * @category   Zend
 * @package    Zend_Search_Lucene
 * @subpackage Index
 */
abstract class AbstractSegmentWriter
{
    /**
     * Expert: The fraction of terms in the "dictionary" which should be stored
     * in RAM.  Smaller values use more memory, but make searching slightly
     * faster, while larger values use less memory and make searching slightly
     * slower.  Searching is typically not dominated by dictionary lookup, so
     * tweaking this is rarely useful.
     *
     * @var integer
     */
    public static $indexInterval = 128;

    /**
     * Expert: The fraction of TermDocs entries stored in skip tables.
     * Larger values result in smaller indexes, greater acceleration, but fewer
     * accelerable cases, while smaller values result in bigger indexes,
     * less acceleration and more
     * accelerable cases. More detailed experiments would be useful here.
     *
     * 0x7FFFFFFF indicates that we don't use skip data
     *
     * Note: not used in current implementation
     *
     * @var integer
     */
    public static $skipInterval = 0x7FFFFFFF;

    /**
     * Expert: The maximum number of skip levels. Smaller values result in
     * slightly smaller indexes, but slower skipping in big posting lists.
     *
     * 0 indicates that we don't use skip data
     *
     * Note: not used in current implementation
     *
     * @var integer
     */
    public static $maxSkipLevels = 0;

    /**
     * Number of docs in a segment
     *
     * @var integer
     */
    protected $_docCount = 0;

    /**
     * Segment name
     *
     * @var string
     */
    protected $_name;

    /**
     * File system adapter.
     *
     * @var \ZendSearch\Lucene\Storage\Directory\DirectoryInterface
     */
    protected $_directory;

    /**
     * List of the index files.
     * Used for automatic compound file generation
     *
     * @var array
     */
    protected $_files = array();

    /**
     * Segment fields. Array of Zend_Search_Lucene_Index_FieldInfo objects for this segment
     *
     * @var array
     */
    protected $_fields = array();

    /**
     * Normalization factors.
     * An array fieldName => normVector
     * normVector is a binary string.
     * Each byte corresponds to an indexed document in a segment and
     * encodes normalization factor (float value, encoded by
     * \ZendSearch\Lucene\Search\Similarity\AbstractSimilarity::encodeNorm())
     *
     * @var array
     */
    protected $_norms = array();


    /**
     * '.fdx'  file - Stored Fields, the field index.
     *
     * @var \ZendSearch\Lucene\Storage\File\FileInterface
     */
    protected $_fdxFile = null;

    /**
     * '.fdt'  file - Stored Fields, the field data.
     *
     * @var \ZendSearch\Lucene\Storage\File\FileInterface
     */
    protected $_fdtFile = null;


    /**
     * Object constructor.
     *
     * @param \ZendSearch\Lucene\Storage\Directory\DirectoryInterface $directory
     * @param string $name
     */
    public function __construct(Directory\DirectoryInterface $directory, $name)
    {
        $this->_directory = $directory;
        $this->_name      = $name;
    }


    /**
     * Add field to the segment
     *
     * Returns actual field number
     *
     * @param \ZendSearch\Lucene\Document\Field $field
     * @return integer
     */
    public function addField(Document\Field $field)
    {
        if (!isset($this->_fields[$field->name])) {
            $fieldNumber = count($this->_fields);
            $this->_fields[$field->name] = new Index\FieldInfo($field->name,
                                                               $field->isIndexed,
                                                               $fieldNumber,
                                                               $field->storeTermVector);

            return $fieldNumber;
        } else {
            $this->_fields[$field->name]->isIndexed       |= $field->isIndexed;
            $this->_fields[$field->name]->storeTermVector |= $field->storeTermVector;

            return $this->_fields[$field->name]->number;
        }
    }

    /**
     * Add fieldInfo to the segment
     *
     * Returns actual field number
     *
     * @param \ZendSearch\Lucene\Index\FieldInfo $fieldInfo
     * @return integer
     */
    public function addFieldInfo(Index\FieldInfo $fieldInfo)
    {
        if (!isset($this->_fields[$fieldInfo->name])) {
            $fieldNumber = count($this->_fields);
            $this->_fields[$fieldInfo->name] = new Index\FieldInfo($fieldInfo->name,
                                                                   $fieldInfo->isIndexed,
                                                                   $fieldNumber,
                                                                   $fieldInfo->storeTermVector);

            return $fieldNumber;
        } else {
            $this->_fields[$fieldInfo->name]->isIndexed       |= $fieldInfo->isIndexed;
            $this->_fields[$fieldInfo->name]->storeTermVector |= $fieldInfo->storeTermVector;

            return $this->_fields[$fieldInfo->name]->number;
        }
    }

    /**
     * Returns array of FieldInfo objects.
     *
     * @return array
     */
    public function getFieldInfos()
    {
        return $this->_fields;
    }

    /**
     * Add stored fields information
     *
     * @param array $storedFields array of \ZendSearch\Lucene\Document\Field objects
     */
    public function addStoredFields($storedFields)
    {
        if (!isset($this->_fdxFile)) {
            $this->_fdxFile = $this->_directory->createFile($this->_name . '.fdx');
            $this->_fdtFile = $this->_directory->createFile($this->_name . '.fdt');

            $this->_files[] = $this->_name . '.fdx';
            $this->_files[] = $this->_name . '.fdt';
        }

        $this->_fdxFile->writeLong($this->_fdtFile->tell());
        $this->_fdtFile->writeVInt(count($storedFields));
        foreach ($storedFields as $field) {
            $this->_fdtFile->writeVInt($this->_fields[$field->name]->number);
            $fieldBits = ($field->isTokenized ? 0x01 : 0x00) |
                         ($field->isBinary ?    0x02 : 0x00) |
                         0x00; /* 0x04 - third bit, compressed (ZLIB) */
            $this->_fdtFile->writeByte($fieldBits);
            if ($field->isBinary) {
                $this->_fdtFile->writeVInt(strlen($field->value));
                $this->_fdtFile->writeBytes($field->value);
            } else {
                $this->_fdtFile->writeString($field->getUtf8Value());
            }
        }

        $this->_docCount++;
    }

    /**
     * Returns the total number of documents in this segment.
     *
     * @return integer
     */
    public function count()
    {
        return $this->_docCount;
    }

    /**
     * Return segment name
     *
     * @return string
     */
    public function getName()
    {
        return $this->_name;
    }

    /**
     * Dump Field Info (.fnm) segment file
     */
    protected function _dumpFNM()
    {
        $fnmFile = $this->_directory->createFile($this->_name . '.fnm');
        $fnmFile->writeVInt(count($this->_fields));

        $nrmFile = $this->_directory->createFile($this->_name . '.nrm');
        // Write header
        $nrmFile->writeBytes('NRM');
        // Write format specifier
        $nrmFile->writeByte((int)0xFF);

        foreach ($this->_fields as $field) {
            $fnmFile->writeString($field->name);
            $fnmFile->writeByte(($field->isIndexed       ? 0x01 : 0x00) |
                                ($field->storeTermVector ? 0x02 : 0x00)
// not supported yet            0x04 /* term positions are stored with the term vectors */ |
// not supported yet            0x08 /* term offsets are stored with the term vectors */   |
                               );

            if ($field->isIndexed) {
                // pre-2.1 index mode (not used now)
                // $normFileName = $this->_name . '.f' . $field->number;
                // $fFile = $this->_directory->createFile($normFileName);
                // $fFile->writeBytes($this->_norms[$field->name]);
                // $this->_files[] = $normFileName;

                $nrmFile->writeBytes($this->_norms[$field->name]);
            }
        }

        $this->_files[] = $this->_name . '.fnm';
        $this->_files[] = $this->_name . '.nrm';
    }



    /**
     * Term Dictionary file
     *
     * @var \ZendSearch\Lucene\Storage\File\FileInterface
     */
    private $_tisFile = null;

    /**
     * Term Dictionary index file
     *
     * @var \ZendSearch\Lucene\Storage\File\FileInterface
     */
    private $_tiiFile = null;

    /**
     * Frequencies file
     *
     * @var \ZendSearch\Lucene\Storage\File\FileInterface
     */
    private $_frqFile = null;

    /**
     * Positions file
     *
     * @var \ZendSearch\Lucene\Storage\File\FileInterface
     */
    private $_prxFile = null;

    /**
     * Number of written terms
     *
     * @var integer
     */
    private $_termCount;


    /**
     * Last saved term
     *
     * @var \ZendSearch\Lucene\Index\Term
     */
    private $_prevTerm;

    /**
     * Last saved term info
     *
     * @var \ZendSearch\Lucene\Index\TermInfo
     */
    private $_prevTermInfo;

    /**
     * Last saved index term
     *
     * @var \ZendSearch\Lucene\Index\Term
     */
    private $_prevIndexTerm;

    /**
     * Last saved index term info
     *
     * @var \ZendSearch\Lucene\Index\TermInfo
     */
    private $_prevIndexTermInfo;

    /**
     * Last term dictionary file position
     *
     * @var integer
     */
    private $_lastIndexPosition;

    /**
     * Create dicrionary, frequency and positions files and write necessary headers
     */
    public function initializeDictionaryFiles()
    {
        $this->_tisFile = $this->_directory->createFile($this->_name . '.tis');
        $this->_tisFile->writeInt((int)0xFFFFFFFD);
        $this->_tisFile->writeLong(0 /* dummy data for terms count */);
        $this->_tisFile->writeInt(self::$indexInterval);
        $this->_tisFile->writeInt(self::$skipInterval);
        $this->_tisFile->writeInt(self::$maxSkipLevels);

        $this->_tiiFile = $this->_directory->createFile($this->_name . '.tii');
        $this->_tiiFile->writeInt((int)0xFFFFFFFD);
        $this->_tiiFile->writeLong(0 /* dummy data for terms count */);
        $this->_tiiFile->writeInt(self::$indexInterval);
        $this->_tiiFile->writeInt(self::$skipInterval);
        $this->_tiiFile->writeInt(self::$maxSkipLevels);

        /** Dump dictionary header */
        $this->_tiiFile->writeVInt(0);                    // preffix length
        $this->_tiiFile->writeString('');                 // suffix
        $this->_tiiFile->writeInt((int)0xFFFFFFFF);       // field number
        $this->_tiiFile->writeByte((int)0x0F);
        $this->_tiiFile->writeVInt(0);                    // DocFreq
        $this->_tiiFile->writeVInt(0);                    // FreqDelta
        $this->_tiiFile->writeVInt(0);                    // ProxDelta
        $this->_tiiFile->writeVInt(24);                   // IndexDelta

        $this->_frqFile = $this->_directory->createFile($this->_name . '.frq');
        $this->_prxFile = $this->_directory->createFile($this->_name . '.prx');

        $this->_files[] = $this->_name . '.tis';
        $this->_files[] = $this->_name . '.tii';
        $this->_files[] = $this->_name . '.frq';
        $this->_files[] = $this->_name . '.prx';

        $this->_prevTerm          = null;
        $this->_prevTermInfo      = null;
        $this->_prevIndexTerm     = null;
        $this->_prevIndexTermInfo = null;
        $this->_lastIndexPosition = 24;
        $this->_termCount         = 0;

    }

    /**
     * Add term
     *
     * Term positions is an array( docId => array(pos1, pos2, pos3, ...), ... )
     *
     * @param \ZendSearch\Lucene\Index\Term $termEntry
     * @param array $termDocs
     */
    public function addTerm($termEntry, $termDocs)
    {
        $freqPointer = $this->_frqFile->tell();
        $proxPointer = $this->_prxFile->tell();

        $prevDoc = 0;
        foreach ($termDocs as $docId => $termPositions) {
            $docDelta = ($docId - $prevDoc)*2;
            $prevDoc = $docId;
            if (count($termPositions) > 1) {
                $this->_frqFile->writeVInt($docDelta);
                $this->_frqFile->writeVInt(count($termPositions));
            } else {
                $this->_frqFile->writeVInt($docDelta + 1);
            }

            $prevPosition = 0;
            foreach ($termPositions as $position) {
                $this->_prxFile->writeVInt($position - $prevPosition);
                $prevPosition = $position;
            }
        }

        if (count($termDocs) >= self::$skipInterval) {
            /**
             * @todo Write Skip Data to a freq file.
             * It's not used now, but make index more optimal
             */
            $skipOffset = $this->_frqFile->tell() - $freqPointer;
        } else {
            $skipOffset = 0;
        }

        $term     = new Index\Term($termEntry->text, $this->_fields[$termEntry->field]->number);
        $termInfo = new Index\TermInfo(count($termDocs), $freqPointer, $proxPointer, $skipOffset);

        $this->_dumpTermDictEntry($this->_tisFile, $this->_prevTerm, $term, $this->_prevTermInfo, $termInfo);
        if (($this->_termCount + 1) % self::$indexInterval == 0) {
            $this->_dumpTermDictEntry($this->_tiiFile, $this->_prevIndexTerm, $term, $this->_prevIndexTermInfo, $termInfo);

            $indexPosition = $this->_tisFile->tell();
            $this->_tiiFile->writeVInt($indexPosition - $this->_lastIndexPosition);
            $this->_lastIndexPosition = $indexPosition;

        }
        $this->_termCount++;
    }

    /**
     * Close dictionary
     */
    public function closeDictionaryFiles()
    {
        $this->_tisFile->seek(4);
        $this->_tisFile->writeLong($this->_termCount);

        $this->_tiiFile->seek(4);
        // + 1 is used to count an additional special index entry (empty term at the start of the list)
        $this->_tiiFile->writeLong(($this->_termCount - $this->_termCount % self::$indexInterval)/self::$indexInterval + 1);
    }


    /**
     * Dump Term Dictionary segment file entry.
     * Used to write entry to .tis or .tii files
     *
     * @param \ZendSearch\Lucene\Storage\File\FileInterface $dicFile
     * @param \ZendSearch\Lucene\Index\Term $prevTerm
     * @param \ZendSearch\Lucene\Index\Term $term
     * @param \ZendSearch\Lucene\Index\TermInfo $prevTermInfo
     * @param \ZendSearch\Lucene\Index\TermInfo $termInfo
     */
    protected function _dumpTermDictEntry(File\FileInterface $dicFile,
                                          &$prevTerm,     Index\Term     $term,
                                          &$prevTermInfo, Index\TermInfo $termInfo)
    {
        if (isset($prevTerm) && $prevTerm->field == $term->field) {
            $matchedBytes = 0;
            $maxBytes = min(strlen($prevTerm->text), strlen($term->text));
            if(!is_numeric($prevTerm->text)){
                while ($matchedBytes < $maxBytes  && $prevTerm->text[$matchedBytes] == $term->text[$matchedBytes]) {
                    $matchedBytes++;
                }
            }


            // Calculate actual matched UTF-8 pattern
            $prefixBytes = 0;
            $prefixChars = 0;
            while ($prefixBytes < $matchedBytes) {
                $charBytes = 1;
                if($term->text[$prefixBytes] != null) {
                    if ((ord($term->text[$prefixBytes]) & 0xC0) == 0xC0) {
                        $charBytes++;
                        if (ord($term->text[$prefixBytes]) & 0x20) {
                            $charBytes++;
                            if (ord($term->text[$prefixBytes]) & 0x10) {
                                $charBytes++;
                            }
                        }
                    }
                }

                if ($prefixBytes + $charBytes > $matchedBytes) {
                    // char crosses matched bytes boundary
                    // skip char
                    break;
                }

                $prefixChars++;
                $prefixBytes += $charBytes;
            }

            // Write preffix length
            $dicFile->writeVInt($prefixChars);
            // Write suffix
            $dicFile->writeString(substr($term->text, $prefixBytes));
        } else {
            // Write preffix length
            $dicFile->writeVInt(0);
            // Write suffix
            $dicFile->writeString($term->text);
        }
        // Write field number
        $dicFile->writeVInt($term->field);
        // DocFreq (the count of documents which contain the term)
        $dicFile->writeVInt($termInfo->docFreq);

        $prevTerm = $term;

        if (!isset($prevTermInfo)) {
            // Write FreqDelta
            $dicFile->writeVInt($termInfo->freqPointer);
            // Write ProxDelta
            $dicFile->writeVInt($termInfo->proxPointer);
        } else {
            // Write FreqDelta
            $dicFile->writeVInt($termInfo->freqPointer - $prevTermInfo->freqPointer);
            // Write ProxDelta
            $dicFile->writeVInt($termInfo->proxPointer - $prevTermInfo->proxPointer);
        }
        // Write SkipOffset - it's not 0 when $termInfo->docFreq > self::$skipInterval
        if ($termInfo->skipOffset != 0) {
            $dicFile->writeVInt($termInfo->skipOffset);
        }

        $prevTermInfo = $termInfo;
    }


    /**
     * Generate compound index file
     */
    protected function _generateCFS()
    {
        $cfsFile = $this->_directory->createFile($this->_name . '.cfs');
        $cfsFile->writeVInt(count($this->_files));

        $dataOffsetPointers = array();
        foreach ($this->_files as $fileName) {
            $dataOffsetPointers[$fileName] = $cfsFile->tell();
            $cfsFile->writeLong(0); // write dummy data
            $cfsFile->writeString($fileName);
        }
        foreach ($this->_files as $fileName) {
            // Get actual data offset
            $dataOffset = $cfsFile->tell();
            // Seek to the data offset pointer
            $cfsFile->seek($dataOffsetPointers[$fileName]);
            // Write actual data offset value
            $cfsFile->writeLong($dataOffset);
            // Seek back to the end of file
            $cfsFile->seek($dataOffset);

            $dataFile = $this->_directory->getFileObject($fileName);

            $byteCount = $this->_directory->fileLength($fileName);
            while ($byteCount > 0) {
                $data = $dataFile->readBytes(min($byteCount, 131072 /*128Kb*/));
                $byteCount -= strlen($data);
                $cfsFile->writeBytes($data);
            }

            $this->_directory->deleteFile($fileName);
        }
    }


    /**
     * Close segment, write it to disk and return segment info
     *
     * @return \ZendSearch\Lucene\Index\SegmentInfo
     */
    abstract public function close();
}

