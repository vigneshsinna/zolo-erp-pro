/*
 * Error help: under every error the app shows (server banners, AJAX messages, field errors, alert() boxes)
 * add a small "How to fix" bar that explains why it happened and what to do next.
 * Entries are tried in order: specific messages first, then whole categories, then a general fallback.
 * Each entry: m = pattern, why = one sentence, fix = steps (or a function of the regex match), go = [label, path].
 */
(function () {
    'use strict';
    var script = document.currentScript;
    var base = ((script && script.dataset.base) || '').replace(/\/$/, '');
    var url = function (path) { return base + path; };

    var H = [
        // ---- Job work ----
        {m: /Reverse the job worker's service bill (\S+) first/i,
            why: 'This GRN already has a processing-charge bill. A GRN cannot be undone while its bill stands.',
            fix: function (r) { return ['Open Buying › Purchase Bills and find ' + r[1] + '.', 'Click Reverse on ' + r[1] + ', give a reason, and post it.', 'Come back to this job work order and reverse the GRN again.']; },
            go: ['Open Purchase Bills', '/purchases']},
        {m: /This is a job work service bill\. Edit it from its job work order/i,
            why: 'This bill carries a job worker\'s processing charge into the cost of the processed goods. A plain purchase edit would cut that link.',
            fix: ['Open Operations › Job Work and the job work order of this bill.', 'Go to the Job Work GRN tab and click Edit bill on that GRN.', 'Correct the item, quantity, rate or charge type, give a reason, and save.']},
        {m: /This GRN has no service bill to edit/i,
            why: 'The bill was already reversed or never booked.',
            fix: ['Reload the page and use Service bill on the GRN to book a new bill.']},
        {m: /Reverse the rework DC (\S+) sent against this GRN first/i,
            why: 'Part of this GRN was sent back to the job worker for rework, so the GRN is still in use.',
            fix: function (r) { return ['On this job work order, open the Material DC tab.', 'Reverse rework DC ' + r[1] + ' (or receive it back first).', 'Then reverse the GRN.']; }},
        {m: /Reverse GRN (.+) before reversing this DC/i,
            why: 'Material on this DC has already been received back. The GRN must be undone before the DC.',
            fix: function (r) { return ['Open the Job Work GRN tab on this order.', 'Reverse ' + r[1] + ' (reverse its service bill first if it has one).', 'Then reverse this DC.']; }},
        {m: /Choose a quarantine warehouse for the rejected quantity|Rejected material needs a separate internal quarantine warehouse/i,
            why: 'Rejected goods are kept apart from good stock, in a second warehouse of your own (not the job worker\'s).',
            fix: ['Pick a Quarantine warehouse in the GRN form, or enter 0 in Rejected.', 'No second warehouse yet? Add one (for example "Quarantine – Rejected Goods") under Settings › Warehouse, then reload this page.'],
            go: ['Open Settings › Warehouse', '/warehouse']},
        {m: /Receipt exceeds remaining dispatch quantity or process loss threshold/i,
            why: 'Accepted + rejected + loss is more than what is still at the job worker, or the loss is above the process limit.',
            fix: ['Check the "outside" quantity shown on the DC line.', 'Reduce accepted / rejected / loss so their total is within it.', 'If the loss really is higher, raise the process\'s max loss % under Operations › Business Profile (or book the extra as a stock loss).']},
        {m: /Bill an active, unbilled receipt on or after its date/i,
            why: 'The GRN is reversed, already billed, or the bill date is before the GRN date.',
            fix: ['Set the bill date on or after the GRN date.', 'If the GRN already shows a service bill, it cannot be billed again.']},
        {m: /Job work bills accept service items only/i,
            why: 'The processing charge must be a Service item, not stock.',
            fix: ['Pick a service item (e.g. "Dyeing charges") in Processing charge item.', 'None listed? Create a product of type Service with its GST rate and SAC code.'],
            go: ['Add a service item', '/products/create']},
        {m: /Material returned unprocessed carries no processing charge/i,
            why: 'This receipt brought material back without processing, so there is nothing to bill.',
            fix: ['Bill only GRNs of processed material.']},
        {m: /Direct dispatch to a customer needs processed output/i,
            why: 'Goods sent straight from the job worker to a customer must be recorded as the processed item.',
            fix: ['Enter the processed output lines on the GRN, or untick direct dispatch and receive into your warehouse.']},
        {m: /Receive an active dispatch on or after its date|Dispatch cannot precede the order|Rework cannot precede the GRN|Receipt cannot precede the job order/i,
            why: 'The date entered is earlier than the document it follows.',
            fix: ['Set the business date on or after the earlier document\'s date.']},
        {m: /Receive into an internal warehouse|Dispatch from an internal warehouse/i,
            why: '"External JWO-…" warehouses are the job workers\' premises, created automatically. They cannot be used here.',
            fix: ['Choose one of your own warehouses (e.g. Central Fabric Godown).']},
        {m: /Enter at least one received or lost quantity/i,
            why: 'All quantities on the GRN are zero.',
            fix: ['Enter the accepted, rejected or loss quantity for at least one line.']},
        {m: /Used process policy is immutable/i,
            why: 'This process is already used on job work orders, so its loss limit and conversion rule are locked for audit.',
            fix: ['Create a new process (new code) with the new rule and use it on new orders.']},

        // ---- Lots, stock, units ----
        {m: /Lot \/ batch "([^"]+)" of (.+) is not in stock/i,
            why: 'Stock going out must come from a lot that already exists in that warehouse.',
            fix: function (r) { return ['Clear the Lot / batch box if ' + r[2] + ' is not kept by lot.', 'Otherwise pick the exact lot that is in stock (check Inventory › Stock by lot).', 'Browsers sometimes autofill this box. Check it holds no party or other text.']; },
            go: ['Open Stock by lot', '/operations/inventory/lots']},
        {m: /has only ([\d.]+) remaining|is not in stock in warehouse|Insufficient (unexpired )?batch stock|Stock quantity for product/i,
            why: 'There is not enough stock in the chosen warehouse (or lot) for this quantity.',
            fix: ['Reduce the quantity, or choose the warehouse / lot that holds the stock.', 'If stock should be there, post the missing purchase / GRN / opening stock first.']},
        {m: /is batch-tracked; specify the batch|needs a batch number|New batch .* needs an expiry date/i,
            why: 'This product is kept by batch / lot, so every movement must name its batch.',
            fix: ['Fill the Batch / lot field (and expiry date for a new batch) on each line of this product.']},
        {m: /needs exactly .* serial numbers|Duplicate serial numbers|Piece .* already exists|must be moved whole/i,
            why: 'Serial / piece tracked items need one unique serial per unit, and a piece moves as a whole.',
            fix: ['Enter exactly one serial per unit, each different.', 'For pieces, select the whole piece instead of part of it.']},
        {m: /No conversion from unit|Select a valid product unit|Select a company-owned unit|unsupported conversion operator/i,
            why: 'The unit on the line is not set up to convert to the product\'s base unit.',
            fix: ['Pick the product\'s own unit, or add the conversion under Products › Units.'],
            go: ['Open Units', '/unit']},
        {m: /Quarantined stock cannot be sold or transferred/i,
            why: 'Goods in quarantine are blocked from normal sale and transfer until inspected.',
            fix: ['Choose a sellable warehouse for this document.', 'To clear quarantine: send job work rejects back with Return / Send for rework on the GRN, return supplier goods on a debit note from the quarantine warehouse, or write off unusable goods.']},
        {m: /Source and Destination warehouses must be different|A transfer needs different source and destination/i,
            why: 'A transfer needs two different warehouses.',
            fix: ['Pick a different destination warehouse.']},

        // ---- GST ----
        {m: /Set a valid (HSN|SAC) code for (.+?) \(/i,
            why: 'GST bills need an HSN (goods) or SAC (services) code that is in your company\'s HSN/SAC list.',
            fix: function (r) { return ['Open the product ' + r[2] + ' and fill HSN / SAC code (goods: 4/6/8 digits, services: 99xxxx).', 'The code must also be listed under Settings › GST & Tax. Add it there if it is new.', 'Save the product, then review this document again.']; },
            go: ['Open Settings › GST', '/settings/gst']},
        {m: /Set a GST rate for (.+?) \(/i,
            why: 'Items on a GST bill need a GST rate.',
            fix: function (r) { return ['Open the product ' + r[1] + ', choose its GST rate, and save.', 'Or set a default rate on its item group under Settings › GST & Tax.']; },
            go: ['Open Products', '/products']},
        {m: /Enter a valid 15-character GSTIN|GSTIN checksum is invalid/i,
            why: 'A GSTIN has exactly 15 characters: 2-digit state code + 10-character PAN + entity number + Z + check character. A wrong GSTIN makes the party "unregistered" and drops GST from its bills.',
            fix: ['Copy the GSTIN exactly from the party\'s invoice or the GST portal.', 'Check the PAN part (5 letters, 4 digits, 1 letter).', 'Leave it empty if the party is not GST registered.']},
        {m: /Enter the GSTIN or state of (.+?) before GST billing/i,
            why: 'GST needs the party\'s state to decide CGST+SGST or IGST.',
            fix: function (r) { return ['Open the party ' + r[1] + ' and enter its GSTIN, or at least its state.']; }},
        {m: /Party GSTIN state differs from its address state|Party registration type and GSTIN disagree|Registration type, GSTIN and state must agree|GSTIN state differs from registration state/i,
            why: 'The GSTIN\'s first two digits are the state code. They must match the state and registration type saved.',
            fix: ['Open the party (or company profile) and correct either the GSTIN or the state.', 'Registered parties need a GSTIN; unregistered ones must have none.']},
        {m: /Select a party before GST preview/i,
            why: 'GST depends on the party\'s state and registration.',
            fix: ['Choose the party first, then review.']},
        {m: /Configure exactly one active Indian GST registration/i,
            why: 'The branch needs one GST registration valid on the document date.',
            fix: ['Open Settings › GST & Tax › Registration and add (or end overlapping) registrations for this branch.'],
            go: ['Open Settings › GST', '/settings/gst']},
        {m: /For GST, enter freight as a classified service line/i,
            why: 'Under GST, freight is taxed as its own service line, not as a lump sum.',
            fix: ['Add freight as a service item line with its GST rate.', 'Tick "Add freight to item cost" if it should go into stock value.']},
        {m: /Supply under LUT needs a Letter of Undertaking/i,
            why: 'Zero-rated export/SEZ supply without IGST needs a valid LUT on file.',
            fix: ['Add the LUT number and validity under Settings › GST & Tax › Exports and TDS.'],
            go: ['Open Settings › GST', '/settings/gst']},
        {m: /SAC must contain six digits starting with 99|Provide a 4, 6 or 8 digit HSN/i,
            why: 'HSN codes are 4, 6 or 8 digits; SAC codes are 6 digits starting with 99.',
            fix: ['Correct the code on the product and save.']},
        {m: /Tax rate differs from the configured product rate/i,
            why: 'The line\'s tax rate was changed from the product\'s GST rate.',
            fix: ['Use Review to let the server apply the product\'s rate, or fix the rate on the product.']},

        // ---- Purchase / sales / orders ----
        {m: /A fulfilment cannot precede its order/i,
            why: 'The bill or receipt date is earlier than the order\'s date.',
            fix: ['Set the bill date on or after the PO/SO date.', 'If the order\'s date is wrong, edit the order (Purchase Orders › Edit) first.']},
        {m: /Reverse or edit on or after the document date \(([^)]+)\)/i,
            why: 'A reversal or edit is dated before the document itself.',
            fix: function (r) { return ['Use a date on or after ' + r[1] + '.']; }},
        {m: /This order has fulfilments/i,
            why: 'Goods have already been received or billed against this order, so it cannot be deleted.',
            fix: ['Use Cancel rest on the order card to cancel only the open quantity.']},
        {m: /An (unbilled )?(purchase )?order cannot (receive|take) payment|Pending invoices cannot receive payment|unbilled order cannot receive payment/i,
            why: 'Payments are recorded against bills, not orders or pending documents.',
            fix: ['Save the order without payment.', 'Load it to a bill when goods arrive, then record the payment on the bill.']},
        {m: /Receipt exceeds the (remaining order quantity|quantity still open on order)/i,
            why: 'More is being received or billed than is still open on the order.',
            fix: ['Reduce the quantity to the open balance shown on the order card.', 'For extra quantity, bill it separately without linking the order.']},
        {m: /Link an active order for the same supplier and warehouse/i,
            why: 'A bill can only fulfil an open order of the same supplier and godown.',
            fix: ['Check the party and godown on the bill match the order, or remove the order link.']},
        {m: /This document has already been reversed/i,
            why: 'This bill was already replaced or reversed.',
            fix: ['Reload the list and edit the newer version (the replacement) instead.']},
        {m: /A source with posted notes cannot be reversed/i,
            why: 'A debit/credit note was posted against this document.',
            fix: ['Reverse the note first, or post an adjustment note instead of reversing.']},
        {m: /Give a reason between 3 and 500 characters|reason .* is required|needs a reason/i,
            why: 'Reversals and edits of posted documents keep a reason for the audit trail.',
            fix: ['Type a short reason (at least 3 characters) and submit again.']},
        {m: /Payment exceeds (the outstanding document amount|invoice total)|Payment must be within the (bill|sale) total/i,
            why: 'The payment is larger than what is still due.',
            fix: ['Reduce the paid amount to the balance due.', 'For an advance, record it as a separate receipt/payment voucher.']},
        {m: /Payment date cannot precede the document date|Due date must be on or after document date|A note cannot precede its source|A return cannot be dated before|Bill date cannot be before the source/i,
            why: 'The date is earlier than the document it depends on.',
            fix: ['Use a date on or after the original document\'s date.']},
        {m: /Return exceeds source quantity|Return at most the unbilled quantity|Nothing is left unbilled to return/i,
            why: 'More is being returned than was received / sold (minus earlier returns).',
            fix: ['Reduce the return quantity to the "already returned" balance shown on the line.']},
        {m: /Return goods from the bill's warehouse or from a quarantine warehouse/i,
            why: 'Goods go back to the supplier from where they were received, or from quarantine where rejected goods wait.',
            fix: ['Choose the bill\'s own godown, or the quarantine warehouse holding the rejected goods.']},
        {m: /Non-sellable returns require a quarantine warehouse|Expired batches must enter quarantine/i,
            why: 'Damaged or expired returns go to a quarantine warehouse, not back to sellable stock.',
            fix: ['Choose "Back to stock" only for good goods; otherwise pick a quarantine warehouse.', 'No quarantine warehouse? Add one under Settings › Warehouse.'],
            go: ['Open Settings › Warehouse', '/warehouse']},
        {m: /An active item already uses this code|Code already exists|Duplicate code within this file/i,
            why: 'Product codes must be unique in the company.',
            fix: ['Use another code, or click the refresh button next to Product Code to generate one.']},
        {m: /Select a catalog product for every row|Select an active catalog product/i,
            why: 'A line has typed text that does not match a product.',
            fix: ['Pick the product from the suggestion list on each row.', 'New item? Use Create item first.']},
        {m: /Add at least one item|must contain at least one product item|Use at least one order line|Use between one and 500 lines/i,
            why: 'The document has no item lines.',
            fix: ['Add at least one item row with quantity and rate.']},
        {m: /Product could not be saved/i,
            why: 'The server refused the product.',
            fix: ['Check the fields marked in red.', 'If nothing is marked, send this message and the time to your administrator.']},

        // ---- Numbers, dates, periods ----
        {m: /Business date is outside the selected financial year|Report dates must fall inside the selected financial year/i,
            why: 'The date belongs to a different financial year from the one selected at the top.',
            fix: ['Switch the FY in the top bar to the year of this date, or change the date.']},
        {m: /locked for posting|period is closed|already closed/i,
            why: 'The books are locked up to this date (period close).',
            fix: ['Use a date after the lock date, or ask an administrator to unlock the period with an audited reason.']},
        {m: /Configure a default series|Bills need a default series|Series does not belong/i,
            why: 'Document numbers come from a numbering series, and this document type has no default one.',
            fix: ['Open Settings › Document series and mark one series of this type as default.'],
            go: ['Open Document series', '/document-series']},
        {m: /Document number already exists|Document number has already been assigned/i,
            why: 'That number is already used.',
            fix: ['Leave the number empty to auto-number, or type an unused number.']},
        {m: /Switch to the branch and financial year the document was entered in/i,
            why: 'The document belongs to another branch or financial year.',
            fix: ['Use the switcher in the top bar to select that branch / FY, then retry.']},

        // ---- Accounting setup ----
        {m: /Configure (an active )?(company )?account mapping|Configure an account for|Configure an active leaf account for|accounts must be configured before posting|account must be configured before posting/i,
            why: 'Posting needs the ledger accounts (sales, purchase, stock, GST, cash…) mapped for this company.',
            fix: ['Open Accounting › Account mappings and map the missing role to a ledger.', 'Then save the document again.'],
            go: ['Open Account mappings', '/accounting/semantic-account-mappings']},
        {m: /Payment account must link to an active (company )?cash or bank|Link an active company cash or bank account/i,
            why: 'Payments need an active cash or bank account.',
            fix: ['Pick a cash/bank account on the payment, or add one under Accounting › Chart of accounts.']},

        // ---- Permissions & company scope ----
        {m: /Permission required|permission required|access denied|administrator required|not allowed/i,
            why: 'Your user role does not include this action.',
            fix: ['Ask an administrator to grant it under Settings › Role Permission, or to do this step for you.'],
            go: ['Open Role Permission', '/role']},
        {m: /Capability is disabled/i,
            why: 'This feature is switched off for the company.',
            fix: ['An administrator can switch it on in Company profile › Features.']},
        {m: /Select a company-owned record|belong(s)? to (another|the same|the selected|this) company|company-owned|outside the (selected|authorized) branch/i,
            why: 'A dropdown on the form is empty, or points to something deleted or from another company / branch.',
            fix: ['Check every required list on the form (party, warehouse, product, unit, account) has a value selected.', 'Reload the page if a record was just created or deleted elsewhere.', 'Make sure the company and branch in the top bar are the right ones.']},

        // ---- More specific messages ----
        {m: /Billed or returned goods cannot be changed/i,
            why: 'This DC/GRN has already been billed or returned, so its lines are fixed.',
            fix: ['Reverse the bill (or the return) that used it first, then change the DC/GRN.']},
        {m: /Goods already received on the GRN count as received on the bill/i,
            why: 'The bill is linked to a GRN, so the stock already came in through the GRN.',
            fix: ['Do not receive the same quantity again on the bill. Leave received quantity as loaded from the GRN.']},
        {m: /Service lines do not receive stock/i,
            why: 'Service items (charges) never go into a warehouse.',
            fix: ['Remove the received quantity from service lines; receive only stock items.']},
        {m: /A cancelled order cannot be amended|A fulfilled line retains its original order rate|Ordered quantity cannot be below fulfilled/i,
            why: 'Part of this order is already received/billed or cancelled, which fixes those quantities and rates.',
            fix: ['Change only the open part of the order.', 'For a new rate or more quantity, create a new order.']},
        {m: /Retain all original line identities|Select a line belonging to this order/i,
            why: 'Order lines that were used cannot be removed.',
            fix: ['Keep the line and use Cancel rest for the quantity you no longer need.']},
        {m: /Freight can be added to item cost only|Manual allocations must equal freight|Landed cost cannot be negative/i,
            why: 'Freight added to item cost is spread over the stock lines of this bill.',
            fix: ['Add freight only on bills that have stock items.', 'With manual allocation, make the line amounts add up exactly to the freight.']},
        {m: /This bill is too large to save as a draft|You already have/i,
            why: 'Drafts have a size and count limit.',
            fix: ['Post the document, or discard old drafts from the tabs at the top, then save again.']},
        {m: /Use Save draft in shared entry/i,
            why: 'Drafts are saved from the draft tabs, not as a "draft" status bill.',
            fix: ['Click Save draft at the bottom of the form.']},
        {m: /Financial year dates overlap/i,
            why: 'Two financial years cannot cover the same dates.',
            fix: ['Start the new year the day after the previous year ends.'],
            go: ['Open Financial years', '/company/financial-years/setup']},
        {m: /Reconcile account balances and open items before closing the year/i,
            why: 'Year close needs ledgers and bill-wise balances to agree.',
            fix: ['Run Accounting › Open items and Trial balance, fix differences, then close the year.']},
        {m: /This series has (numbered|already numbered) documents|Used series cannot be reconfigured|next number can only move forward/i,
            why: 'Numbers already printed on documents can never change.',
            fix: ['Create a new series with the new format and make it the default.'],
            go: ['Open Document series', '/document-series']},
        {m: /GST invoices and notes require at most 16 letters/i,
            why: 'GST law allows invoice numbers of at most 16 characters.',
            fix: ['Shorten the series prefix/suffix (or padding) in a new series and make it default.'],
            go: ['Open Document series', '/document-series']},
        {m: /The tax set on (.+?) no longer exists/i,
            why: 'The product points to a tax that was deleted or switched off.',
            fix: function (r) { return ['Open the product ' + r[1] + ', choose its tax again, and save.']; }},
        {m: /Discount exceeds line total|GST discount exceeds taxable line value/i,
            why: 'A discount cannot be larger than the value it reduces.',
            fix: ['Lower the discount, or check the quantity and rate on the line.']},
        {m: /Manufacturing date cannot follow expiry/i,
            why: 'A batch cannot expire before it was made.',
            fix: ['Correct the manufacturing or expiry date of the batch.']},
        {m: /Double-entry unbalanced|Every voucher line must have a nonzero amount|Journal amounts must be nonnegative/i,
            why: 'A voucher must balance: total debit equals total credit, with no zero lines.',
            fix: ['Check each line has an amount on one side only.', 'Make debit and credit totals equal before saving.']},
        {m: /Contra requires two distinct cash or bank accounts|Contra vouchers transfer between cash and bank|Payment credits cash\/bank/i,
            why: 'Contra moves money between your own cash/bank accounts; Payment pays out of, and Receipt brings into, cash/bank.',
            fix: ['Pick the voucher type that matches the money movement, with a cash/bank account on the right side.']},
        {m: /PDC requires cheque number|Cleared PDC amounts exceed|Only cleared PDC records may link|Cheque and receipt fields are only available for PDC/i,
            why: 'Post-dated cheques need full cheque details and can link only to a receipt once cleared.',
            fix: ['Fill cheque number, bank, amount and status.', 'Mark the cheque Cleared before linking it to a receipt of the same party.']},

        // ---- Categories: every other message gets the help of its kind (error boxes only, never in strict mode) ----
        'categories',
        {m: /cannot precede|cannot follow|cannot be (dated )?before|on or after|must be after|after the latest|date .* (outside|inside)/i,
            why: 'The dates are in the wrong order. A document cannot be dated before the one it follows.',
            fix: ['Check the date on this form against the related document\'s date and correct it.']},
        {m: /exceeds|cannot exceed|cannot be below|at most|within the|too large|too long|must be (positive|nonnegative)|positive (amount|quantity)|must be finite/i,
            why: 'A quantity, amount or length is outside what is allowed here.',
            fix: ['Check the numbers on the form (quantity, rate, amount, discount) against the balance or limit shown.', 'Reduce the value, or split it into a separate document.']},
        {m: /^Select (an? )?(active|valid|existing|reviewed|authorized|distinct)|of this company|belonging to this company|Select a variant|Tick at least one|Choose /i,
            why: 'A required choice on the form is missing or not valid for this company.',
            fix: ['Pick a value in each required dropdown (party, warehouse, item, variant, account, branch).', 'Reload the page if the item was just created.']},
        {m: /journal|voucher|ledger|allocation|open item|account (is|does|already|type)|posting role|leaf account|cheque|PDC|collection|brokerage/i,
            why: 'This is an accounting rule that keeps the books balanced and traceable.',
            fix: ['Check the accounts, party and amounts on the voucher.', 'If you are unsure, ask your accountant / administrator with this message.'],
            go: ['Open Chart of accounts', '/accounting/chart-of-accounts']},
        {m: /order|quotation|fulfil/i,
            why: 'This is an order rule: orders track what is ordered, received/billed and cancelled.',
            fix: ['Check the order\'s open quantity on its card, and the party / godown / date on this form.']},
        {m: /return|note|exchange/i,
            why: 'Returns and notes must match what was originally sold or bought.',
            fix: ['Check the source bill, lines and quantities on this return or note.']},
        {m: /series|document number|numbering/i,
            why: 'Document numbering is controlled by Document series.',
            fix: ['Check the series for this document type under Settings › Document series.'],
            go: ['Open Document series', '/document-series']},
        {m: /job worker|processing charge|dispatch|GRN|\bDC\b|rework/i,
            why: 'This is a job work rule: material sent out (DC), received back (GRN) and billed must stay in step.',
            fix: ['Check the DC / GRN quantities and dates on this job work order.', 'Undo in reverse order: service bill → GRN → DC.']},
        {m: /BOM|recipe|production|component|output|scrap|yield/i,
            why: 'This is a manufacturing rule: the BOM defines what is consumed and produced.',
            fix: ['Check the BOM\'s components, output item, unit and effective dates (Operations › BOM & Production).']},
        {m: /stock|batch|lot\b|serial|piece|warehouse|identity|movement|FEFO|dimension|revaluation|quarantine/i,
            why: 'This is a stock rule: quantities, lots, serials and warehouses must match what is actually held.',
            fix: ['Check the item, warehouse, lot / serial and quantity on each line.', 'See what is in stock under Inventory › Stock by lot.'],
            go: ['Open Stock by lot', '/operations/inventory/lots']},
        {m: /GST|GSTIN|PAN|e-invoic|e-way|tax|HSN|SAC|SEZ|LUT/i,
            why: 'This is a GST rule, needed so bills and returns file correctly.',
            fix: ['Check the GST rate / HSN on the items and the GSTIN / state of the party and your company.'],
            go: ['Open Settings › GST', '/settings/gst']},
        {m: /payment|discount|TDS|settlement|tender/i,
            why: 'This is a payment rule: payments settle posted bills within their balance.',
            fix: ['Check the bill is posted, the amount is within the balance, and the discount / TDS terms apply.']},
        {m: /CSV|import|UTF-8|file/i,
            why: 'The import file has a problem.',
            fix: ['Download the template again, fill it, save as CSV (UTF-8) and upload.'],
            go: ['Open product import', '/products/import/review']},
        {m: /project|install|site\b/i,
            why: 'This is a project rule: serials move allocate → dispatch → install → commission in order.',
            fix: ['Complete the earlier project step first, with a later date.']},
        {m: /company|branch|group|member|profile|timezone|capabilit|registration|address/i,
            why: 'This is a company setup rule.',
            fix: ['Check Settings › Company profile (and GST registration) for this company.'],
            go: ['Open Company profile', '/settings/company']},
        {m: /posted|reversed|reversal|post /i,
            why: 'Posted documents are fixed. They can only be reversed (and replaced), in order.',
            fix: ['Reverse the documents that depend on this one first, then retry.']},
        {m: /date|YYYY-MM-DD/i,
            why: 'A date on the form is missing or not valid.',
            fix: ['Pick the date again with the calendar.']},
        {m: /draft/i,
            why: 'Drafts are kept per user in the tabs at the top of the form.',
            fix: ['Post or discard drafts you no longer need, then try again.']},
        {m: /alias|alternate name/i,
            why: 'Alternate names link another wording to an existing product.',
            fix: ['Enter a short name and choose whether it applies to everyone or one party.'],
            go: ['Open Alternate names', '/product-aliases']},
        {m: /reviewed|workflow|path\b|Phase \d|awaits|acceptance/i,
            why: 'This option is not switched on for normal entry in this company yet.',
            fix: ['Use the standard option (for example Cash, Bank or Credit), or ask your administrator to enable it.']},
        {m: /must be an object|must be a list|Unsupported|positive integer|context|Invalid |Unknown /i,
            why: 'The page sent data the server did not expect (often an outdated page).',
            fix: ['Reload the page (Ctrl+F5) and enter it again.', 'If it repeats, send this message and the screen name to your administrator.']},

        // ---- Safety checks (audit history) ----
        {m: /immutable|cannot be deleted|must be reversed, not deleted|post a reversal/i,
            why: 'Posted records are kept for audit and are never changed in place.',
            fix: ['Use Reverse (or Edit, which reverses and replaces) on the document instead of changing or deleting it.']},
        {m: /changed in another window|Reload before|Reload its current lines|changed after (review|submission)/i,
            why: 'Someone (or another tab) changed this record after you opened it.',
            fix: ['Reload the page to see the latest version, then redo your change.']},
        {m: /Key (was )?already used|idempotency|retry key/i,
            why: 'This exact save was already sent once (double click or retry).',
            fix: ['Reload the list. The first save probably went through. Do not enter it again.']},
        {m: /migration|reviewed (conversion|linkage|attribution|commercial)|requires review|requires reconciliation|Legacy /i,
            why: 'This old record needs a one-time data review before it can be changed.',
            fix: ['Leave it as is and enter a new document instead.', 'Send this message and the document number to your administrator for review.']},
        {m: /precision|decimal\(18,4\)|four decimal/i,
            why: 'An amount or quantity has too many decimals or is too large.',
            fix: ['Round quantities/rates to at most 4 decimals and check for an extra zero.']}
    ];

    // Anything else still gets a next step, so no error is left without guidance.
    var FALLBACK = {why: 'The server refused this save to keep your data correct.',
        fix: ['Read the message above, correct the field it names, and save again.', 'If it repeats, send this message (and the document number) to your administrator.']};

    function lookup(text, strict) {
        for (var i = 0; i < H.length; i++) {
            if (H[i] === 'categories') { if (strict) return null; continue; }
            var r = text.match(H[i].m);
            if (r) return {entry: H[i], match: r};
        }
        return strict ? null : {entry: FALLBACK, match: null};
    }

    function steps(found) {
        var fix = found.entry.fix;
        return typeof fix === 'function' ? fix(found.match) : fix;
    }

    function build(found) {
        var box = document.createElement('details');
        box.className = 'error-help';
        box.setAttribute('data-error-help', '1');
        var list = steps(found);
        var summary = document.createElement('summary');
        var tag = document.createElement('span');
        tag.className = 'error-help-tag';
        tag.textContent = 'How to fix';
        summary.appendChild(tag);
        summary.appendChild(document.createTextNode(' ' + list[0]));
        box.appendChild(summary);
        var why = document.createElement('p');
        why.className = 'error-help-why';
        why.textContent = 'Why: ' + found.entry.why;
        box.appendChild(why);
        var ol = document.createElement('ol');
        ol.className = 'error-help-steps';
        list.forEach(function (step) { var li = document.createElement('li'); li.textContent = step; ol.appendChild(li); });
        box.appendChild(ol);
        if (found.entry.go) {
            var a = document.createElement('a');
            a.className = 'error-help-link';
            a.href = url(found.entry.go[1]);
            a.textContent = found.entry.go[0] + ' →';
            box.appendChild(a);
        }
        return box;
    }

    // Error boxes always get help (the fallback if nothing specific matches). Field messages and status lines that
    // also show successes get only specific help, so "This field is required." or "Order deleted." stay quiet.
    var BOXES = '.alert-danger, .auth-alert-danger, .toast-error .toast-message';
    var STRICT = '.validation-msg, .invalid-feedback, label.error, .text-danger, [data-order-feedback], [data-orders-status]';
    var hosts = [];

    function visible(el) { return el.isConnected && (el.offsetParent !== null || !!el.closest('.modal.show')); }

    // A bar goes away with its error: host removed, hidden, no longer an error, or showing a different message.
    function cleanup() {
        hosts = hosts.filter(function (h) {
            var keep = visible(h.host) && h.host.matches(BOXES + ',' + STRICT + ',li') && h.bar.isConnected
                && h.host.getAttribute('data-error-help-for') === text(h.host);
            if (!keep) { h.bar.remove(); h.host.removeAttribute('data-error-help-for'); }
            return keep;
        });
    }

    function text(target) {
        return Array.prototype.map.call(target.childNodes, function (n) {
            return n.nodeType === 1 && n.hasAttribute('data-error-help') ? '' : n.textContent;
        }).join(' ').replace(/\s+/g, ' ').trim();
    }

    function attach(el, strict) {
        if (el.closest('[data-error-help]') || !visible(el)) return;
        // The bar's own numbered steps are <li> too; only the error's list items are messages.
        var items = Array.prototype.filter.call(el.querySelectorAll('li'), function (li) { return !li.closest('[data-error-help]'); });
        var targets = items.length ? items : [el];
        targets.forEach(function (target) {
            var message = text(target);
            if (message.length < 8 || target.getAttribute('data-error-help-for') === message) return;
            target.setAttribute('data-error-help-for', message);
            var found = lookup(message, strict);
            if (!found) return;
            var bar = build(found);
            if (target.tagName === 'LI') target.appendChild(bar); else target.insertAdjacentElement('afterend', bar);
            hosts.push({host: target, bar: bar});
        });
    }

    function scan() {
        cleanup();
        document.querySelectorAll(BOXES).forEach(function (el) { attach(el, false); });
        document.querySelectorAll(STRICT).forEach(function (el) { attach(el, true); });
    }

    var pending = false;
    function schedule() {
        if (pending) return;
        pending = true;
        setTimeout(function () { pending = false; scan(document); }, 60);
    }

    function start() {
        scan(document);
        new MutationObserver(function (mutations) {
            for (var i = 0; i < mutations.length; i++) {
                var node = mutations[i].target;
                if (node.nodeType !== 1) node = node.parentElement;
                if (node && !node.closest('[data-error-help]')) { schedule(); return; }
            }
        }).observe(document.body, {childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['class', 'style', 'hidden']});
    }

    // Native alert() boxes cannot hold a bar, so the steps are added to their text.
    var nativeAlert = window.alert;
    window.alert = function (message) {
        var text = String(message == null ? '' : message);
        var found = lookup(text, true);
        if (found) text += '\n\nHow to fix:\n' + steps(found).map(function (s, i) { return (i + 1) + '. ' + s; }).join('\n');
        return nativeAlert.call(window, text);
    };

    window.zoloErrorHelp = {lookup: lookup, steps: steps, scan: scan};
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
})();
