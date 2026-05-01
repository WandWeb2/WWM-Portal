import re

with open("api/modules/clients.php", "r") as f:
    content = f.read()

# Let's split content using HEAD block and ========= block and >>>>>>>> block
lines = content.split('\n')
new_lines = []
in_head = False
in_main = False
head_lines = []
main_lines = []

for line in lines:
    if line.startswith("<<<<<<< HEAD"):
        in_head = True
        continue
    elif line.startswith("======="):
        in_head = False
        in_main = True
        continue
    elif line.startswith(">>>>>>>"):
        in_main = False
        # Process what we collected
        # head_lines has our optimized handleImportCRMClients and OLD handleImportStripeClients
        # main_lines has the OLD handleImportCRMClients and NEW handleImportStripeClients
        # We want to keep our handleImportCRMClients, and main's handleImportStripeClients.
        # But wait, looking at the main_lines, it actually has the start of handleImportStripeClients and then the file continues, because main_lines was just:
        # function handleImportCRMClients...
        # function handleImportStripeClients(....

        # Actually it's simple: we just output our optimized handleImportCRMClients, and discard main's handleImportCRMClients.
        # And we discard our OLD handleImportStripeClients, and output whatever starts main's handleImportStripeClients.

        # Let's find handleImportStripeClients in head_lines
        head_crm = []
        for hline in head_lines:
            if hline.startswith("function handleImportStripeClients"):
                break
            head_crm.append(hline)

        # Let's find handleImportStripeClients in main_lines
        main_stripe = []
        found_stripe = False
        for mline in main_lines:
            if mline.startswith("function handleImportStripeClients"):
                found_stripe = True
            if found_stripe:
                main_stripe.append(mline)

        new_lines.extend(head_crm)
        new_lines.extend(main_stripe)
        continue

    if in_head:
        head_lines.append(line)
    elif in_main:
        main_lines.append(line)
    else:
        new_lines.append(line)

with open("api/modules/clients.php", "w") as f:
    f.write("\n".join(new_lines))
print("Conflict resolved via python!")
