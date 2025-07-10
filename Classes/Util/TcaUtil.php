<?php

declare(strict_types=1);

namespace Gaumondp\PguBrofixExtras\Util;

use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class TcaUtil
{
    /**
     * Traverses a FlexForm data structure definition (usually from TCA $fieldConfig['ds'])
     * and extracts field configurations.
     *
     * @param array<mixed> $flexFormDs The FlexForm data structure (e.g., from TCA['tt_content']['columns']['pi_flexform']['config']['ds'])
     * @param array<mixed> $fieldConfigs (by reference) Array to populate with field configurations, keyed by field name.
     */
    protected static function traverseFlexFormDsForConfig(array $flexFormDs, array &$fieldConfigs): void
    {
        foreach ($flexFormDs as $sheetKey => $sheetValue) {
            if ($sheetKey === 'meta' || !is_array($sheetValue)) {
                continue;
            }
            // Check if this level is a language section (e.g., <lDEF LGL="default">)
            if (isset($sheetValue['ROOT']['el']) && is_array($sheetValue['ROOT']['el'])) { // Standard structure with sheets/languages
                foreach ($sheetValue['ROOT']['el'] as $fieldName => $fieldDefinition) {
                    if (is_array($fieldDefinition) && isset($fieldDefinition['TCEforms']['config'])) {
                        $fieldConfigs[$fieldName] = $fieldDefinition['TCEforms']; // Store the 'config' and 'label' part
                        $fieldConfigs[$fieldName]['label'] = $fieldDefinition['TCEforms']['label'] ?? $fieldDefinition['label'] ?? $fieldName;
                    } elseif (is_array($fieldDefinition) && !isset($fieldDefinition['TCEforms']) && count($fieldDefinition) > 0) {
                        // This might be a container or a different structure, recurse if it contains 'el'
                        if(isset($fieldDefinition['el']) && is_array($fieldDefinition['el'])) {
                            self::traverseFlexFormDsForConfig($fieldDefinition['el'], $fieldConfigs); // Recurse into nested elements
                        } else if (isset($fieldDefinition['config'])) { // Direct config at this level
                             $fieldConfigs[$fieldName] = $fieldDefinition;
                             $fieldConfigs[$fieldName]['label'] = $fieldDefinition['label'] ?? $fieldName;
                        }
                    }
                }
            } elseif (isset($sheetValue['el']) && is_array($sheetValue['el'])) { // Structure without explicit sheets e.g. within a section
                 self::traverseFlexFormDsForConfig($sheetValue['el'], $fieldConfigs);
            } elseif (isset($sheetValue['TCEforms']['config'])) { // Field directly under a key (e.g. inside a section)
                $fieldConfigs[$sheetKey] = $sheetValue['TCEforms'];
                $fieldConfigs[$sheetKey]['label'] = $sheetValue['TCEforms']['label'] ?? $sheetValue['label'] ?? $sheetKey;
            }
        }
    }


    /**
     * Traverses the parsed FlexForm XML data to find a value for a given field name.
     *
     * @param string $fieldName The FlexForm field name to search for.
     * @param array<mixed> $flexFormData Parsed XML data of the FlexForm.
     * @return string|null The value of the field, or null if not found.
     */
    protected static function findValueInFlexFormData(string $fieldName, array $flexFormData): ?string
    {
        foreach ($flexFormData as $key => $data) {
            if (!is_array($data)) {
                continue;
            }
            if (isset($data[$fieldName]['vDEF'])) {
                return (string)$data[$fieldName]['vDEF'];
            }
            // If it's a language layer (e.g. lDEF) or sheet (sDEF)
            if (isset($data['sDEF'][$fieldName]['vDEF'])) { // Check common sheet structure
                 return (string)$data['sDEF'][$fieldName]['vDEF'];
            }
            foreach($data as $sheetKey => $sheetData) { // Iterate through sheets if any
                if(is_array($sheetData) && isset($sheetData[$fieldName]['vDEF'])) {
                    return (string)$sheetData[$fieldName]['vDEF'];
                }
                 // Deeper search for nested structures (e.g., sections)
                if (is_array($sheetData)) {
                    $foundValue = self::findValueInFlexFormData($fieldName, $sheetData);
                    if ($foundValue !== null) {
                        return $foundValue;
                    }
                }
            }
        }
        return null;
    }

    /**
     * Gets all field configurations and their values from a FlexForm.
     *
     * @param string $table The database table name.
     * @param string $field The FlexForm field name in the table.
     * @param array<string,mixed> $row The record row containing the FlexForm XML.
     * @param array<mixed> $fieldTcaConfig The TCA configuration for the FlexForm field itself.
     * @return array<string, array{'value': string, 'config': array<mixed>, 'label': string}>
     *               An array where keys are FlexForm field names and values are arrays
     *               containing 'value', 'config', and 'label'.
     */
    public static function getFlexformFieldsWithConfig(string $table, string $field, array $row, array $fieldTcaConfig): array
    {
        if (empty($row[$field]) || empty($fieldTcaConfig['ds'])) {
            return [];
        }

        $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
        // Ensure $row[$field] is a string. It might be null or something else if field is empty.
        $flexXml = (string)($row[$field] ?? '');
        if (empty($flexXml)) {
            return [];
        }

        $parsedXmlData = $flexFormTools->parseDataStructureByIdentifier($flexXml, $table, $field, $row);
        // $parsedXmlData is already the data array, not the raw XML string for xml2array
        // $flexFormData = GeneralUtility::xml2array($cleanedFlexformString); // This was from original code

        $fieldDefinitions = [];
        if (is_array($fieldTcaConfig['ds'])) { // DS directly in TCA
            self::traverseFlexFormDsForConfig($fieldTcaConfig['ds'], $fieldDefinitions);
        } elseif (is_string($fieldTcaConfig['ds'])) { // DS from FILE: reference
            $dsArray = GeneralUtility::xml2array(GeneralUtility::getUrl(GeneralUtility::getFileAbsFileName($fieldTcaConfig['ds'])));
            if ($dsArray) {
                self::traverseFlexFormDsForConfig($dsArray, $fieldDefinitions);
            }
        }


        $results = [];
        foreach ($fieldDefinitions as $fieldName => $configAndLabel) {
            $value = self::findValueInFlexFormData($fieldName, $parsedXmlData['data'] ?? []); // $parsedXmlData contains sheets under 'data'
            if ($value !== null) { // Only include fields that have a value
                $results[$fieldName] = [
                    'value' => $value,
                    'config' => $configAndLabel, // This already contains the 'config' array from TCEforms
                    'label' => $configAndLabel['label'] ?? $fieldName,
                ];
            }
        }
        return $results;
    }
}
