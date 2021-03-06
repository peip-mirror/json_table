<?php
namespace JsonTable\Analyse;

/**
 * Perform lexical analysis.
 * 
 * @package JsonTable
 */
class Lexical extends Analyse implements AnalyseInterface
{
    /**
     * @var string The description for fields with invalid formats.
     */
    const ERROR_INVALID_PATTERN = 'There are <strong>%d</strong> fields that don\'t have the correct pattern:';

    /**
     * @var string The description for fields with invalid formats.
     */
    const ERROR_INVALID_FORMAT = 'There are <strong>%d</strong> fields that don\'t have the correct format:';

    /**
     * @var string The description for rows with missing columns.
     */
    const ERROR_REQUIRED_FIELD_MISSING_DATA = 'There are <strong>%d</strong> required fields with missing data:';

    /**
     * @var string The description for rows with missing columns.
     */
    const ERROR_INCORRECT_COLUMN_COUNT = 'There are the wrong number of columns';

    /**
     * @var array   The current CSV row being analysed.
     */
    private $currentCsvRow;

    /**
     * @var int The position of the CSV column currently being analysed.
     */
    private $csvColumnPosition;

    /**
     * @var int The position of the current CSV row row in the CSV file.
     */
    private $rowNumber;

    /**
     * @var object  The schema definition for the column currently being analysed.
     */
    private $schemaColumn;

    /**
     * @var int The number of columns in the currently analysed row.
     */
    private $columnCount;

    /**
     * @var int The number of columns expected in each row.
     * This is taken from the CSV header row.
     */
    private $expectedColumnCount;

    /**
     * @var string  The pattern to validate the current field against.
     */
    private $pattern;

    /**
     * @var string  The format to validate the current field against.
     */
    private $format;

    /**
     * @var bool    Whether the file is valid.
     */
    private $valid;


    /**
     * Validate that all fields are of the correct type, format and pattern.
     * This also checks that each CSV row has the expected number of columns.
     *
     * @return  boolean Is all data lexically valid.
     */
    public function validate()
    {
        $this->valid = true;
        $this->rowNumber = 1;

        parent::rewindFilePointerToFirstData();

        while ($currentCsvRow = parent::loopThroughFileRows()) {
            $this->currentCsvRow = $currentCsvRow;
            
            if (!$this->checkRowHasExpectedColumnCount()) {
                $this->handleUnexpectedColumnCount();
            }

            for ($this->csvColumnPosition = 0; $this->csvColumnPosition < $this->columnCount; $this->csvColumnPosition++) {
                $this->schemaColumn = $this->getSchemaColumnFromCsvColumnPosition($this->csvColumnPosition);

                if (!$this->checkMandatoryColumnHasData()) {
                    $this->handleInvalidMandatoryColumn();

                    if ($this->stopIfInvalid) {
                        return false;
                    }
                }

                if (!$this->validateSpecificFormat()) {
                    $this->handleInvalidFormat();

                    if ($this->stopIfInvalid) {
                        return false;
                    }
                }

                if (!$this->validatePattern()) {
                    $this->handleInvalidPattern();

                    if ($this->stopIfInvalid) {
                        return false;
                    }
                }
            }

            $this->rowNumber++;
        }

        $this->setRowsAnalysedStatistic();

        return $this->valid;
    }


    /**
     * Check that the specified row has the expected number of columns.
     * The expected number of columns is the number of columns in the CSV header row.
     *
     * @return boolean  Whether the current row has the expected number of columns.
     */
    private function checkRowHasExpectedColumnCount()
    {
        $this->columnCount = count($this->currentCsvRow);
        $this->expectedColumnCount = count(parent::$headerColumns);

        return ($this->expectedColumnCount === $this->columnCount);
    }


    /**
     * Set an error and update the application as the current row has an unexpected number of columns.
     *
     * @return  void
     */
    private function handleUnexpectedColumnCount()
    {
        $errorMessage = "Row $this->rowNumber has $this->columnCount columns but should have $this->expectedColumnCount.";
        $this->error->setError(self::ERROR_INCORRECT_COLUMN_COUNT, $errorMessage);
        $this->statistics->setErrorRow($this->rowNumber);
    }


    /**
     * Check whether the current column is mandatory and if so, whether it has data in it.
     *
     * @return  boolean Whether the column has data in it.
     */
    private function checkMandatoryColumnHasData()
    {
        if ($this->isColumnMandatory($this->schemaColumn)) {
            return ('' !== $this->currentCsvRow[$this->csvColumnPosition]);
        }

        return true;
    }


    /**
     * Set an error and update the application as the current column is mandatory and has no data in it.
     *
     * @return  void
     */
    private function handleInvalidMandatoryColumn()
    {
        $errorMessage = $this->schemaColumn->name . " on row $this->rowNumber is missing.";
        $this->error->setError(self::ERROR_REQUIRED_FIELD_MISSING_DATA, $errorMessage);
        $this->statistics->setErrorRow($this->rowNumber);
        $this->valid = false;
    }


    /**
     * Check that the data in the current field is of a valid format as specified in the schema for this column.
     * This instantiates and passed the data to the format validator for this field type.
     *
     * @return  boolean Whether the current field is of a valid format.
     */
    private function validateSpecificFormat()
    {
        $type = $this->getColumnType($this->schemaColumn);
        $this->format = $this->getColumnFormat($this->schemaColumn);
        $validator = $this->instantiateValidator(Analyse::VALIDATION_TYPE_FORMAT, $type);
        $validator->setInput($this->currentCsvRow[$this->csvColumnPosition]);

        return $validator->validateFormat($this->format);
    }


    /**
     * Set an error and update the application as the current data didn't match the specified format.
     *
     * @return  void
     */
    private function handleInvalidFormat()
    {
        $errorMessage  = "The data in column " . $this->schemaColumn->name ." on row $this->rowNumber doesn't ";
        $errorMessage .= "match the required format of $this->format.";
        $this->error->setError(self::ERROR_INVALID_FORMAT, $errorMessage);
        $this->statistics->setErrorRow($this->rowNumber);
        $this->valid = false;
    }


    /**
     * Get the pattern of the specified column.
     *
     * @return  string  The pattern or null if no pattern is specified.
     */
    private function getColumnPattern()
    {
        $propertyExists = property_exists($this->schemaColumn, 'constraints') &&
            property_exists($this->schemaColumn->constraints, 'pattern');

        return $propertyExists ? $this->schemaColumn->constraints->pattern : null;
    }


    /**
     * Check that the input matches the specified pattern.
     *
     * @return  boolean Is the data valid.
     */
    private function validatePattern()
    {
        $this->pattern = $this->getColumnPattern();
        $input = $this->currentCsvRow[$this->csvColumnPosition];

        if (is_null($this->pattern) || '' === $input) {
            return true;
        }

        return (false !== filter_var($input, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => $this->pattern]]));
    }


    /**
     * Set an error and update the application as the current data didn't match the specified pattern.
     *
     * @return  void
     */
    private function handleInvalidPattern()
    {
        $errorMessage  = "The data in column " . $this->schemaColumn->name . " on row $this->rowNumber doesn't ";
        $errorMessage .= "match the required pattern of $this->pattern.";
        $this->error->setError(self::ERROR_INVALID_PATTERN, $errorMessage);
        $this->statistics->setErrorRow($this->rowNumber);
        $this->valid = false;
    }


    /**
     * Add the number of rows analysed to the statistics.
     *
     * @return  void
     */
    private function setRowsAnalysedStatistic()
    {
        $this->statistics->setRowsAnalysed($this->rowNumber - 1);
    }
}