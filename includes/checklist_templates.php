<?php
/**
 * includes/checklist_templates.php
 *
 * Predefined document checklists per case type. These are sensible starting
 * points based on common Canadian immigration requirements — review and
 * adjust the wording/items below to match your firm's actual checklists.
 * Staff can also add, remove, or edit any item in the browser before a
 * checklist is ever sent to a client, so this file only needs to be "close
 * enough" to save re-typing the same list every time.
 *
 * 'is_required' below is just the default state of each item's "Required
 * document" checkbox when the checklist is opened for editing — staff can
 * flip any item before sending. Items are marked optional here when the
 * source checklist itself says something conditional ("if applicable",
 * "provide any that applies", "if you have received one in the past 5
 * years", etc.) rather than always needed.
 *
 * A blank instructions string is intentional for a few items below (e.g.
 * "Birth Certificate", "Marriage Certificate") where the source checklist
 * genuinely has no description beyond the document name itself.
 *
 * Each item: ['doc_name' => ..., 'instructions' => ..., 'is_required' => bool]
 */

function getChecklistTemplates(): array
{
    $langTestBlurb = "Provide a completed test taken within the last 2 years. It may be:\n"
        . "• CELPIP - https://www.celpip.ca/celpip-general/\n"
        . "• IELTS - https://ieltsregistration.britishcouncil.org/\n"
        . "• PTE Core - https://www.pearsonpte.com/pte-core/";

    $prevRelationshipBlurb = "Provide any of the applicable documents:\n"
        . "• Annulment Certificate\n"
        . "• Certificate of Divorce\n"
        . "• Death Certificate of previous spouse";

    $commonLawBlurb = "Provide any that applies:\n"
        . "• Affidavit of common-law\n"
        . "• Government IDs showing the same address and date of issue\n"
        . "• Utility bills showing the same address and date of issue\n"
        . "• Birth Certificate of the child together\n"
        . "• Proof of joint utility accounts";

    $employmentLetterBlurb = "On company letterhead, please ensure the letter includes:\n"
        . "• Date of the letter;\n"
        . "• Position title(s);\n"
        . "• Employment start date;\n"
        . "• Hours worked per week;\n"
        . "• Job duties;\n"
        . "• Salary/wage information;\n"
        . "• Name, position, contact number, and signature of the employer.";

    $proofOfFundsFamilyBlurb = "Provide 6 months statement for all bank accounts you have:\n"
        . "• chequing\n"
        . "• savings\n"
        . "• international accounts";

    $policeCertificate18Blurb = "For each country or territory where you were physically present at least 6 months since age of 18 years old.\n\nPhilippines:";

    $upfrontMedicalBlurb = "Instructions on how to get:\n\n"
        . "• Contact one of the Doctor's near you for an appointment, confirm what you will need to bring: (https://secure.cic.gc.ca/PanelPhysicianMedecinDesigne/en/Home)\n\n"
        . "• The panel physician will perform a complete medical exam and may refer you for chest x-rays and laboratory tests. Please tell the doctor about any previous or existing medical conditions.\n\n"
        . "• Once your medical exam has been completed, the doctor will give you a document confirming that you underwent a medical exam (IMM 1017B or Upfront Medical Report form).";

    $canadianRelativeStatusBlurb = "This can be either Canadian Passport, Canadian Birth Certificate or Permanent Residence Card";
    $canadianRelativeBloodBlurb = "Copy of all birth and marriage certificates in the family showing your relationship.";
    $canadianRelativeStayBlurb = "provide BOTH proof of residence of your Canadian relative:\n"
        . "• government ID showing address/driver's license in Canada\n"
        . "• current (phone, hydro, payment) bill showing address and date";

    $prPhotosBlurb = "Scanned copy of two (2) recent photos for yourself. See the IRCC photo specifications for details.";

    $financialSupportBlurb = "Provide at least one of the following:\n\n"
        . "• Proof of a Canadian bank account in your name, if you've transferred money to Canada.\n"
        . "• Guaranteed Investment Certificate (GIC) from a participating Canadian financial institution.\n"
        . "• Proof of a student or education loan from a bank\n"
        . "• Your bank statements for the past 6 months\n"
        . "• A bank draft that can be converted to Canadian dollars\n\n"
        . "See the current amount required at: https://www.canada.ca/en/immigration-refugees-citizenship/services/study-canada/study-permit/get-documents/financial-support.html";

    $tiesToHomeCountryLongBlurb = "Provide any that applies:\n"
        . "• Certificate of Deed of Sale\n"
        . "• Certificate of Ownership\n"
        . "• Certificate of Registration\n"
        . "• Birth Certificate of Dependent children left in home country\n"
        . "• Leave of Absence from Current Employer\n"
        . "• Letter from School (minor)";

    // ---- Shared across the 4 sponsorship templates below (Spousal Outside
    // Canada, Common-Law, Conjugal, Child) — several requirements are worded
    // identically across all of them in the source checklists. ----
    $sponsorEmploymentBlurb = "If employed, employment letter with the information:\n"
        . "• Period of employment\n"
        . "• Salary\n"
        . "• Regular hours worked per week\n"
        . "• Job title\n"
        . "• Name of supervisor and contact information\n\n"
        . "If self-employed, please provide the following:\n"
        . "• Business permit or business documents showing Sponsor's name\n"
        . "• Article of incorporation, if available\n"
        . "• Tax documents showing business income\n"
        . "• Business bank statement\n"
        . "• Personal bank statement\n\n"
        . "If retired, please provide the following:\n"
        . "• T4A Canada Pension Plan / Statement of Pension, Retirement, Annuity, and other Income\n"
        . "• Personal bank statement, including savings, chequing, and investment accounts";

    $prevRelationshipMarriageBlurb = "Previous Marriage Certificate and any of the applicable documents:\n"
        . "• Annulment Certificate\n"
        . "• Certificate of Divorce\n"
        . "• Death Certificate of previous spouse";

    $countrySpecificPhBlurb = "Please provide scanned copy of any the following country specific requirement from the Philippines:\n"
        . "• CENOMAR - Certificate of No Marriages from Philippines Statistics Authority\n"
        . "• (PSA)Advisory on Marriages from Philippines Statistics Authority (PSA)";

    $sponsorshipPoliceCertBlurb = "Please provide police certificate for all countries you have resided for six (6) consecutive months or longer since the age of 18.";

    $prPhotosClickHereBlurb = "Please see the IRCC photo specifications page for the exact requirements.";

    $recentMedicalExamBlurb = "If you have taken a medical exam in the last 12 months, please provide a copy of the information sheet from the panel physician, along with information on the name of the physician and place of medical examination.";

    $proofOfCommunicationBlurb = "Screenshot of messages, letters, emails, or social media conversations, or other documented proof of contact between Applicant and the sponsor.";

    $proofOfVisitsBlurb = "Airline tickets, boarding passes, passport with entry-exit stamps, accommodation bookings, and photos supporting sponsor's visits to the Applicant.";

    $importantDocumentsBlurb = "For example, employment or insurance benefits indicating the Principal applicant is recognized at the sponsor's partner.";

    $friendsFamilyRecognitionBlurb = "For example, signed letters from friends/family with an accompanying ID, social media information showing a public relationship, etc.";

    return [
        'LMIA' => [
            ['doc_name' => 'A Valid municipal business licence - File summary', 'instructions' => 'You can obtain it by login to the Companies Online', 'is_required' => true],
            ['doc_name' => 'Previous positive LMIA', 'instructions' => 'If you received approval in last 2 years, give us a copy of all pages.', 'is_required' => false],
            ['doc_name' => 'T2SCH100 Balance sheet information – Schedule 100 (for corporations)', 'instructions' => 'You may obtain it from your accountant or CPA.', 'is_required' => false],
            ['doc_name' => 'T2SCH125 Income statement information – Schedule 125 (for corporations)', 'instructions' => 'You may obtain it from your accountant or CPA.', 'is_required' => false],
            ['doc_name' => 'T2042 Statement of farming activities (for self-employed farmer or a partner in a farming business)', 'instructions' => 'You may obtain it from your accountant or CPA.', 'is_required' => false],
            ['doc_name' => 'T2125 Statement of business or professional activities (for sole proprietorships)', 'instructions' => 'You may obtain it from your accountant or CPA.', 'is_required' => false],
            ['doc_name' => 'T3010 Registered charity information return (for registered charities)', 'instructions' => 'You may obtain it from your accountant or CPA.', 'is_required' => false],
            ['doc_name' => 'T5013SCH1 Net income (loss) for income tax purposes – Schedule 1 (for partnerships)', 'instructions' => 'You may obtain it from your accountant or CPA.', 'is_required' => false],
        ],
        'CITIZENSHIP' => [
            ['doc_name' => 'Passport Biopage', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Passport stamps', 'instructions' => 'All pages of your passport with stamps.', 'is_required' => true],
            ['doc_name' => 'Legal Name Change Documents', 'instructions' => "If applicable, provide:\n• Marriage certificate\n• Divorce certificate\n• Any other legal name-change document", 'is_required' => false],
            ['doc_name' => 'English or French Language Test', 'instructions' => $langTestBlurb, 'is_required' => true],
            ['doc_name' => '2 Valid IDs', 'instructions' => "Provide any that applies (front and back):\n• PR Card\n• driver's licence\n• health card\n• senior citizen card\n• age of majority card\n• foreign ID issued by the foreign government\n• passport", 'is_required' => true],
            ['doc_name' => 'Police Clearance Certificates', 'instructions' => "For each country or territory where you were physically present for 183 days or more during the 4 years immediately before your application.\n\nPhilippines:", 'is_required' => true],
            ['doc_name' => 'CRA Notices of Assessment (NOAs): for the last five years', 'instructions' => 'You may obtain this from your accountant or Canada Revenue Agency (CRA) account.', 'is_required' => true],
        ],
        'WORK PERMIT' => [
            ['doc_name' => 'Valid Passport', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History or Visas', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'Birth Certificate', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'High School Credentials', 'instructions' => 'Diploma and Transcript of Records.', 'is_required' => true],
            ['doc_name' => 'Post secondary credentials', 'instructions' => 'Diploma and Transcript of Records.', 'is_required' => true],
            ['doc_name' => 'Proof of Means', 'instructions' => 'Past 6 months of bank statements (mm/yy to mm/yy).', 'is_required' => true],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Employment Letter', 'instructions' => "Please ensure all information are present:\n• Date of Letter\n• Position Title\n• Employment Start date\n• Hours worked per week\n• Job duties\n• Wage Information\n• Name, position, contact number & signature of employer", 'is_required' => true],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Paystubs', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Certificates', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Proof of filing taxes', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Business ownership', 'instructions' => "Provide any that applies:\n• Business Permit\n• Mayors Permit\n• Barangay Permit\n• DTI Permit\n• Articles of Incorporation", 'is_required' => false],
            ['doc_name' => 'Police Certificate', 'instructions' => $policeCertificate18Blurb, 'is_required' => true],
            ['doc_name' => 'Upfront Medical Exam', 'instructions' => $upfrontMedicalBlurb, 'is_required' => true],
            ['doc_name' => 'Proof of Ties in Home Country', 'instructions' => "Provide any that applies:\n• Certificate of Deed of Sale\n• Certificate of Ownership\n• Certificate of Registration\n• Birth Certificate of Dependent children left in home country", 'is_required' => false],
        ],
        'WP EXT (BUYING TIME)' => [
            ['doc_name' => 'Valid Passport', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History or Visas', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'All Canadian Permits', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'MPNP Expression of Interest Submitted - Summary', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of Biometrics Validity', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Birth Certificate', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Means', 'instructions' => 'Past 6 months of a bank statements (mm/yy to mm/yy).', 'is_required' => true],
            ['doc_name' => 'Paystubs from current employer', 'instructions' => '2 most recent paystubs.', 'is_required' => true],
            ['doc_name' => 'Employment Letter from Current Employer', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'T4 from current employer', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of Ties in Home Country', 'instructions' => "Provide any that applies:\n• Certificate of Deed of Sale\n• Certificate of Ownership\n• Certificate of Registration\n• Birth Certificate of Dependent children left in home country", 'is_required' => false],
        ],
        'WP EXT (LMIA)' => [
            ['doc_name' => 'Valid Passport', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History or Visas', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'All Canadian Permits', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Biometrics Validity', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Birth Certificate', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Job Offer / Employment Contract', 'instructions' => 'Please provide the copy with signature of you and your employer.', 'is_required' => true],
            ['doc_name' => 'Positive LMIA', 'instructions' => 'Please provide a valid positive LMIA with your name as listed foreign worker.', 'is_required' => true],
            ['doc_name' => 'Most Updated Resume', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'High School Credentials', 'instructions' => 'Diploma and Transcript of Records.', 'is_required' => true],
            ['doc_name' => 'Post secondary credentials', 'instructions' => 'Diploma and Transcript of Records.', 'is_required' => true],
            ['doc_name' => 'Proof of Means', 'instructions' => 'Past 6 months of a bank statements (mm/yy to mm/yy).', 'is_required' => true],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Employment Letter', 'instructions' => "Please ensure all information are present:\n• Date of Letter\n• Position Title\n• Employment Start date\n• Hours worked per week\n• Job duties\n• Wage Information\n• Name, position, contact number & signature of employer", 'is_required' => true],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Paystubs', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Certificates', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Proof of filing taxes', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Business ownership', 'instructions' => "Provide any that applies:\n• Business Permit\n• Mayors Permit\n• Barangay Permit\n• DTI Permit\n• Articles of Incorporation", 'is_required' => false],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): T4', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of Ability to Work (Name of employer): Paystubs from current employer', 'instructions' => '2 most recent paystubs.', 'is_required' => true],
        ],
        'WP EXT (WPSL)' => [
            ['doc_name' => 'Valid Passport', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History or Visas', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'All Canadian Permits', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Biometrics Validity', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Valid Nomination', 'instructions' => '', 'is_required' => true],
            ['doc_name' => "Screenshot of Employer's Gckey showing offer number", 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Employer compliance receipt', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Birth Certificate', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Means', 'instructions' => 'Past 6 months of a bank statements (mm/yy to mm/yy).', 'is_required' => true],
            ['doc_name' => 'Paystubs from current employer', 'instructions' => '2 most recent paystubs.', 'is_required' => true],
            ['doc_name' => 'Employment Letter from Current Employer', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'T4 from current employer', 'instructions' => '', 'is_required' => true],
        ],
        'PR through MPNP (Non-Express Entry)' => [
            ['doc_name' => 'Valid Passport (Applicant, Spouse, Children)', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History (Applicant, Spouse, Children)', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'Canadian permits (old and current) - Applicant', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Spouse', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Child', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Birth certificate (Applicant, Spouse, Children)', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'English or French Language Test - Applicant', 'instructions' => $langTestBlurb, 'is_required' => true],
            ['doc_name' => 'High School Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => true],
            ['doc_name' => 'Post secondary Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => true],
            ['doc_name' => 'Canadian Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => false],
            ['doc_name' => 'High School Credentials - Spouse', 'instructions' => '• Diploma and Transcript', 'is_required' => false],
            ['doc_name' => 'Post secondary Credentials - Spouse', 'instructions' => '• Diploma and Transcript', 'is_required' => false],
            ['doc_name' => 'Canadian Credentials - Spouse', 'instructions' => '• Diploma and Transcript', 'is_required' => false],
            ['doc_name' => 'Employment letter - name of employer', 'instructions' => $employmentLetterBlurb, 'is_required' => true],
            ['doc_name' => 'Paystubs - name of employer', 'instructions' => 'From mm/yy to mm/yy.', 'is_required' => true],
            ['doc_name' => 'Proof of funds', 'instructions' => $proofOfFundsFamilyBlurb, 'is_required' => true],
            ['doc_name' => 'T4 for the previous tax year - name of employer', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Notice of Assessment for the previous tax year', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of relationship with Canadian relatives: proof of status of your Canadian relative', 'instructions' => $canadianRelativeStatusBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of relationship with Canadian relatives: proof of blood relatedness', 'instructions' => $canadianRelativeBloodBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of relationship with Canadian relatives: proof of current stay in Canada', 'instructions' => $canadianRelativeStayBlurb, 'is_required' => false],
        ],
        'PR only (Non-Express Entry)' => [
            ['doc_name' => 'Valid Passport (Applicant, Spouse, Children)', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History (Applicant, Spouse, Children)', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'Canadian permits (old and current) - Applicant', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Spouse', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Child', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Birth certificate (Applicant, Spouse, Children)', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'English or French Language Test - Applicant', 'instructions' => $langTestBlurb, 'is_required' => true],
            ['doc_name' => 'Police Certificate', 'instructions' => $policeCertificate18Blurb, 'is_required' => true],
            ['doc_name' => 'Permanent residence photos', 'instructions' => $prPhotosBlurb, 'is_required' => true],
        ],
        'PR only (Express Entry)' => [
            ['doc_name' => 'Valid Passport (Applicant, Spouse, Children)', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History (Applicant, Spouse, Children)', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'Canadian permits (old and current) - Applicant', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Spouse', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Child', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Birth certificate (Applicant, Spouse, Children)', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'English or French Language Test - Applicant', 'instructions' => $langTestBlurb, 'is_required' => true],
            ['doc_name' => 'Police Certificate', 'instructions' => $policeCertificate18Blurb, 'is_required' => true],
            ['doc_name' => 'Previous Immigration Medical Exam (IME)', 'instructions' => 'If you have completed an IME in the past 5 years, please provide us a copy.', 'is_required' => false],
            ['doc_name' => 'Permanent residence photos', 'instructions' => $prPhotosBlurb, 'is_required' => true],
            ['doc_name' => 'Education Credential Assessment (ECA)', 'instructions' => 'If you have received one in the past 5 years, kindly provide us with a scanned copy.', 'is_required' => false],
            ['doc_name' => 'High School Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => true],
            ['doc_name' => 'Post secondary Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => true],
            ['doc_name' => 'Canadian Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => false],
            ['doc_name' => 'High School Credentials - Spouse', 'instructions' => '• Diploma and Transcript', 'is_required' => false],
            ['doc_name' => 'Post secondary Credentials - Spouse', 'instructions' => '• Diploma and Transcript', 'is_required' => false],
            ['doc_name' => 'Canadian Credentials - Spouse', 'instructions' => '• Diploma and Transcript', 'is_required' => false],
            ['doc_name' => 'Employment letter - name of employer', 'instructions' => $employmentLetterBlurb, 'is_required' => true],
            ['doc_name' => 'Paystubs - name of employer', 'instructions' => 'From mm/yy to mm/yy.', 'is_required' => true],
            ['doc_name' => 'Proof of funds', 'instructions' => $proofOfFundsFamilyBlurb, 'is_required' => true],
            ['doc_name' => 'T4 for the previous tax year - name of employer', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Notice of Assessment for the previous tax year', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of relationship with Canadian relatives: proof of status of your Canadian relative', 'instructions' => $canadianRelativeStatusBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of relationship with Canadian relatives: proof of blood relatedness', 'instructions' => $canadianRelativeBloodBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of relationship with Canadian relatives: proof of current stay in Canada', 'instructions' => $canadianRelativeStayBlurb, 'is_required' => false],
        ],
        'STUDENT VISA' => [
            ['doc_name' => 'Previous Application Package - Applicant', 'instructions' => "• Refusal Letter\n• Previously Submitted Forms and Documents to IRCC", 'is_required' => false],
            ['doc_name' => 'Duolingo - Applicant', 'instructions' => "https://englishtest.duolingo.com/applicants\n\nYou may practice the test for free. Once ready, you can click \"PURCHASE A TEST\".", 'is_required' => true],
            ['doc_name' => 'High School Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => true],
            ['doc_name' => 'Post secondary Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => true],
            ['doc_name' => 'Valid Passport (Applicant, Spouse, Children)', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History (Applicant, Spouse, Children)', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'Canadian permits (old and current) - Applicant', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Spouse', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Child', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Birth certificate (Applicant, Spouse, Children)', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Means of Financial Support - Applicant', 'instructions' => $financialSupportBlurb, 'is_required' => true],
            ['doc_name' => 'Proof of Tuition (1st year)', 'instructions' => 'Please provide it once you have received the Letter of Acceptance from the school.', 'is_required' => true],
            ['doc_name' => 'Police Certificate', 'instructions' => $policeCertificate18Blurb, 'is_required' => true],
            ['doc_name' => 'Upfront Medical Exam - Applicant, Spouse, Dependent', 'instructions' => $upfrontMedicalBlurb, 'is_required' => true],
            ['doc_name' => 'Letter of Support', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Study Plan', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of Ties in Home Country', 'instructions' => $tiesToHomeCountryLongBlurb, 'is_required' => false],
        ],
        'VISITOR / VISITOR EXTENSION / VISITOR RESTORATION' => [
            ['doc_name' => 'Previous Application Package - Applicant', 'instructions' => "• Refusal Letter\n• Previously Submitted Forms and Documents to IRCC", 'is_required' => false],
            ['doc_name' => 'High School Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => true],
            ['doc_name' => 'Post secondary Credentials - Applicant', 'instructions' => '• Diploma and Transcript', 'is_required' => true],
            ['doc_name' => 'Valid Passport (Applicant, Spouse, Children)', 'instructions' => 'Scanned copy of the information page.', 'is_required' => true],
            ['doc_name' => 'Travel History (Applicant, Spouse, Children)', 'instructions' => 'All pages of your passport with entry, exit stamps and visa counterfoil.', 'is_required' => true],
            ['doc_name' => 'Canadian permits (old and current) - Applicant', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Spouse', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Canadian permits (old and current) - Child', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Birth certificate (Applicant, Spouse, Children)', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Marriage Certificate', 'instructions' => '', 'is_required' => false],
            ['doc_name' => 'Proof of Previous Relationship', 'instructions' => $prevRelationshipBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Common-Law Relationship', 'instructions' => $commonLawBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Means of Financial Support - Applicant', 'instructions' => $financialSupportBlurb, 'is_required' => true],
            ['doc_name' => 'Proof of Ties in Home Country', 'instructions' => $tiesToHomeCountryLongBlurb, 'is_required' => false],
            ['doc_name' => 'Canadian Passport or PR Card (Front and back) - Supporter in Canada', 'instructions' => '', 'is_required' => true],
            ['doc_name' => "Manitoba Health Card - Supporter in Canada", 'instructions' => '', 'is_required' => true],
            ["doc_name" => "Manitoba Driver's License - Supporter in Canada", 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Recent Bank Statement - Supporter in Canada', 'instructions' => '• 6 months bank statements from mm/yy to mm/yy', 'is_required' => true],
            ['doc_name' => 'Two (2) Recent Paystubs - Supporter in Canada', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Notice of Assessment - Supporter in Canada', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Proof of Relationship to Applicant', 'instructions' => 'e.g., birth certificate(s)', 'is_required' => true],
            ['doc_name' => 'Proof of event booking', 'instructions' => "• Invitation card/letter with Applicant's name\n• Proof of venue booking\n• Proof of catering\n• etc.", 'is_required' => false],
            ['doc_name' => 'Medical Insurance Policy - the Principal Applicant', 'instructions' => 'Let us know if you need assistance securing this document.', 'is_required' => true],
            ['doc_name' => 'Provisional Flight Booking - the Principal Applicant', 'instructions' => 'Let us know if you need assistance securing this document.', 'is_required' => true],
            ['doc_name' => 'Proof of Relationship - the Principal Applicant', 'instructions' => "Provide all of the following:\n\n"
                . "A) Proof of Communication\n"
                . "B) Proof of Financial Support\n"
                . "C) 20 Photos, each with the date and a brief description\n"
                . "D) Proof that your relationship is recognized by friends and/or family (letters from friends/family, social media information showing a public relationship, etc.)\n"
                . "E) Proof of Gifts, each with the date and a brief description", 'is_required' => false],
            ['doc_name' => 'Employment letter - name of employer', 'instructions' => $employmentLetterBlurb, 'is_required' => true],
        ],
        'SPOUSAL SPONSORSHIP IN CANADA' => [
            ["doc_name" => "Sponsor's proof of status", 'instructions' => 'Canadian citizenship certificate/card or PR card — clear scan of both sides if applicable.', 'is_required' => true],
            ["doc_name" => "Sponsor's proof of income", 'instructions' => 'Most recent Notice of Assessment (NOA) from CRA, plus a recent T4 or employment letter confirming current income.', 'is_required' => true],
            ['doc_name' => 'Marriage certificate', 'instructions' => 'Official government-issued marriage certificate. Include an English/French translation if needed.', 'is_required' => true],
            ['doc_name' => 'Relationship evidence', 'instructions' => 'Photos together over time, chat/call logs, joint bank accounts or lease, and anything else showing an ongoing genuine relationship.', 'is_required' => true],
            ["doc_name" => "Applicant's passport", 'instructions' => "Clear colour scan of the applicant's photo page, all four corners visible.", 'is_required' => true],
            ["doc_name" => "Applicant's current status document", 'instructions' => 'Only if the applicant is already in Canada — their current study/work permit or visitor record.', 'is_required' => false],
            ['doc_name' => 'Police clearance certificates', 'instructions' => 'One certificate from every country the applicant has lived in for 6+ months since turning 18, each less than a year old.', 'is_required' => true],
            ['doc_name' => 'Digital photos', 'instructions' => 'One recent colour photo each for both sponsor and applicant, meeting IRCC photo specifications.', 'is_required' => true],
            ['doc_name' => 'Statutory declaration of relationship', 'instructions' => 'Optional — a signed, witnessed statement describing the history and genuineness of your relationship, useful if you have limited other evidence.', 'is_required' => false],
        ],
        'SPOUSAL SPONSORSHIP OUTSIDE CANADA' => [
            ["doc_name" => "Sponsor's Canadian Passport", 'instructions' => 'Provide the full bio and signature page.', 'is_required' => true],
            ["doc_name" => "Sponsor's PR Card", 'instructions' => 'Front and back of your permanent residence card.', 'is_required' => true],
            ["doc_name" => "Sponsor's Previous Relationship", 'instructions' => $prevRelationshipMarriageBlurb, 'is_required' => false],
            ['doc_name' => 'Notice of Assessment for 2025', 'instructions' => 'You may obtain it from your accountant or CRA account.', 'is_required' => true],
            ["doc_name" => "Sponsor's Employment/Source of Income", 'instructions' => $sponsorEmploymentBlurb, 'is_required' => true],
            ['doc_name' => 'Passport (Applicant, all dependents)', 'instructions' => 'Scanned copy of the bio and signature page.', 'is_required' => true],
            ['doc_name' => 'Canadian visa and permit', 'instructions' => 'Passport page with visa and permit from IRCC.', 'is_required' => true],
            ['doc_name' => 'Birth Certificate (Applicant, all dependents)', 'instructions' => '', 'is_required' => true],
            ["doc_name" => "Applicant's Previous Relationship", 'instructions' => $prevRelationshipMarriageBlurb, 'is_required' => false],
            ['doc_name' => 'Country Specific Requirement', 'instructions' => $countrySpecificPhBlurb, 'is_required' => true],
            ['doc_name' => 'Police Certificate (Applicant, all dependents over 18 years old)', 'instructions' => $sponsorshipPoliceCertBlurb, 'is_required' => true],
            ['doc_name' => 'Permanent Residence Photos (Applicant, all dependents)', 'instructions' => $prPhotosClickHereBlurb, 'is_required' => true],
            ['doc_name' => 'Medical Exam, if already available', 'instructions' => $recentMedicalExamBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Communication', 'instructions' => $proofOfCommunicationBlurb, 'is_required' => true],
            ['doc_name' => 'Proof of Visits', 'instructions' => $proofOfVisitsBlurb, 'is_required' => true],
            ['doc_name' => '20 Photos', 'instructions' => 'Provide a date and a small description of each photo.', 'is_required' => true],
            ['doc_name' => 'Other Proof of Relationship', 'instructions' => "Please provide at least two of the following:\n\n"
                . "• Important Documents: For example, employment or insurance benefits indicating the Principal applicant is recognized at the sponsor's partner.\n"
                . "• Proof of Financial Support: Documentary evidence of financial support between you and your sponsor, and/or shared expenses.\n"
                . "• Proof that your relationship is recognized by friends and/or family: For example, signed letters from friends/family with an accompanying ID, social media information showing a public relationship, etc.", 'is_required' => true],
        ],
        'COMMON-LAW SPONSORSHIP' => [
            ["doc_name" => "Sponsor's Canadian Passport", 'instructions' => 'Provide the full bio and signature page.', 'is_required' => true],
            ["doc_name" => "Sponsor's PR Card", 'instructions' => 'Front and back of your permanent residence card.', 'is_required' => true],
            ["doc_name" => "Sponsor's Previous Relationship", 'instructions' => $prevRelationshipMarriageBlurb, 'is_required' => false],
            ['doc_name' => 'Notice of Assessment for 2025', 'instructions' => 'You may obtain it from your accountant or CRA account.', 'is_required' => true],
            ["doc_name" => "Sponsor's Employment/Source of Income", 'instructions' => $sponsorEmploymentBlurb, 'is_required' => true],
            ['doc_name' => 'Passport (Applicant, all dependents)', 'instructions' => 'Scanned copy of the bio and signature page.', 'is_required' => true],
            ['doc_name' => 'Canadian visa and permit', 'instructions' => 'Passport page with visa and permit from IRCC.', 'is_required' => true],
            ['doc_name' => 'Birth Certificate (Applicant, all dependents)', 'instructions' => '', 'is_required' => true],
            ["doc_name" => "Applicant's Previous Relationship", 'instructions' => $prevRelationshipMarriageBlurb, 'is_required' => false],
            ['doc_name' => 'Country Specific Requirement', 'instructions' => $countrySpecificPhBlurb, 'is_required' => true],
            ['doc_name' => 'Police Certificate (Applicant, all dependents over 18 years old)', 'instructions' => $sponsorshipPoliceCertBlurb, 'is_required' => true],
            ['doc_name' => 'Permanent Residence Photos (Applicant, all dependents)', 'instructions' => $prPhotosClickHereBlurb, 'is_required' => true],
            ['doc_name' => 'Medical Exam, if already available', 'instructions' => $recentMedicalExamBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of cohabitation', 'instructions' => "Provide at least two (2) evidence that you have been living together for at least a year:\n"
                . "• Proof of Joint Ownership of Residential Property\n"
                . "• Rental Agreement Showing both you and your sponsor as occupants\n"
                . "• Proof of Joint Utility Accounts - For example, electricity, gas, telephone, internet, joint credit card or bank accounts\n"
                . "• Vehicle Insurance showing you and your sponsor has the same address\n"
                . "• Government Issued documents showing your and your sponsor has the same address\n"
                . "• Other documents showing the same address such as cell phone bills, paystubs, tax forms, bank or credit card statements, insurance policies. (Submit a minimum of 1 document for each person)", 'is_required' => true],
            ['doc_name' => '20 Photos', 'instructions' => 'Provide a date and a small description of each photo.', 'is_required' => true],
            ['doc_name' => 'Important Documents', 'instructions' => $importantDocumentsBlurb, 'is_required' => true],
            ['doc_name' => 'Proof of Financial Support', 'instructions' => 'Documentary evidence of financial support between you and your sponsor, and/or shared expenses.', 'is_required' => true],
            ['doc_name' => 'Proof that your relationship is recognized by friends and/or family', 'instructions' => $friendsFamilyRecognitionBlurb, 'is_required' => true],
        ],
        'CONJUGAL SPONSORSHIP' => [
            ["doc_name" => "Sponsor's Canadian Passport", 'instructions' => 'Provide the full bio and signature page.', 'is_required' => true],
            ["doc_name" => "Sponsor's PR Card", 'instructions' => 'Front and back of your permanent residence card.', 'is_required' => true],
            ["doc_name" => "Sponsor's Previous Relationship", 'instructions' => $prevRelationshipMarriageBlurb, 'is_required' => false],
            ['doc_name' => 'Notice of Assessment for 2025', 'instructions' => 'You may obtain it from your accountant or CRA account.', 'is_required' => true],
            ["doc_name" => "Sponsor's Employment/Source of Income", 'instructions' => $sponsorEmploymentBlurb, 'is_required' => true],
            ['doc_name' => 'Passport (Applicant, all dependents)', 'instructions' => 'Scanned copy of the bio and signature page.', 'is_required' => true],
            ['doc_name' => 'Canadian visa and permit', 'instructions' => 'Passport page with visa and permit from IRCC.', 'is_required' => true],
            ['doc_name' => 'Birth Certificate (Applicant, all dependents)', 'instructions' => '', 'is_required' => true],
            ["doc_name" => "Applicant's Previous Relationship", 'instructions' => $prevRelationshipMarriageBlurb, 'is_required' => false],
            ['doc_name' => 'Country Specific Requirement', 'instructions' => $countrySpecificPhBlurb, 'is_required' => true],
            ['doc_name' => 'Police Certificate (Applicant, all dependents over 18 years old)', 'instructions' => $sponsorshipPoliceCertBlurb, 'is_required' => true],
            ['doc_name' => 'Permanent Residence Photos (Applicant, all dependents)', 'instructions' => $prPhotosClickHereBlurb, 'is_required' => true],
            ['doc_name' => 'Medical Exam, if already available', 'instructions' => $recentMedicalExamBlurb, 'is_required' => false],
            ['doc_name' => 'Written Statement', 'instructions' => 'Written explanation why you and your sponsor are unable to marry or to live together and explain why.', 'is_required' => true],
            ['doc_name' => 'Proof of Communication', 'instructions' => $proofOfCommunicationBlurb, 'is_required' => true],
            ['doc_name' => 'Proof of Visits', 'instructions' => $proofOfVisitsBlurb, 'is_required' => true],
            ['doc_name' => '20 Photos', 'instructions' => 'Provide a date and a small description of each photo.', 'is_required' => true],
            ['doc_name' => 'Proof of Financial Support/dependency', 'instructions' => 'Joint bank accounts, remittances, and joint ownership of assets.', 'is_required' => true],
            ['doc_name' => 'Letter of Support', 'instructions' => 'At least two letters from individuals with personal knowledge of your relationship supporting your declaration that the relationship is genuine.', 'is_required' => true],
            ['doc_name' => 'Important Documents', 'instructions' => $importantDocumentsBlurb, 'is_required' => true],
            ['doc_name' => 'Proof that your relationship is recognized by friends and/or family', 'instructions' => $friendsFamilyRecognitionBlurb, 'is_required' => true],
        ],
        'CHILD SPONSORSHIP' => [
            ['doc_name' => 'Canadian Passport (Sponsor and co-signer)', 'instructions' => 'Provide the full bio and signature page.', 'is_required' => true],
            ['doc_name' => 'PR Card (Sponsor and co-signer)', 'instructions' => 'Front and back of your permanent residence card.', 'is_required' => true],
            ['doc_name' => 'Notice of Assessment for 2025 (Sponsor and co-signer)', 'instructions' => 'You may obtain it from your accountant or CRA account.', 'is_required' => true],
            ["doc_name" => "Sponsor's Employment/Source of Income (Sponsor and co-signer)", 'instructions' => $sponsorEmploymentBlurb, 'is_required' => true],
            ['doc_name' => 'Passport (Applicant)', 'instructions' => 'Scanned copy of the bio and signature page.', 'is_required' => true],
            ['doc_name' => 'Canadian visa and permit', 'instructions' => 'Passport page with visa and permit from IRCC.', 'is_required' => true],
            ['doc_name' => 'Birth Certificate (Applicant)', 'instructions' => '', 'is_required' => true],
            ['doc_name' => 'Country Specific Requirement', 'instructions' => $countrySpecificPhBlurb, 'is_required' => true],
            ['doc_name' => 'Police Certificate (Applicant)', 'instructions' => $sponsorshipPoliceCertBlurb, 'is_required' => true],
            ['doc_name' => 'Permanent Residence Photos (Applicant)', 'instructions' => $prPhotosClickHereBlurb, 'is_required' => true],
            ['doc_name' => 'Medical Exam, if already available', 'instructions' => $recentMedicalExamBlurb, 'is_required' => false],
            ['doc_name' => 'Proof of Communication', 'instructions' => $proofOfCommunicationBlurb, 'is_required' => true],
            ['doc_name' => 'Proof of Visits', 'instructions' => $proofOfVisitsBlurb, 'is_required' => true],
            ['doc_name' => '10 Photos', 'instructions' => 'Provide a date and a small description of each photo.', 'is_required' => true],
            ['doc_name' => 'Proof of Financial Support/dependency', 'instructions' => 'Evidence of financial support for the Applicant.', 'is_required' => true],
            ['doc_name' => 'School documents', 'instructions' => "Documents listing sponsor's name as the parent (academic or transcript of record, letter from school, etc.).", 'is_required' => true],
        ],
    ];
}
