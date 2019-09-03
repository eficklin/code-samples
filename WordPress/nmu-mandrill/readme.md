This plugin redefines the native `wp_mail()` function to use the Mandrill transactional service from MailChimp. Should Mandrill not be available, then the plugin falls back to a copy of that native `wp_mail()` function.

In addition to that basic purpose, there is also included in this plugin a command line script for ad hoc bulk sends of transactional messages.

The basic usage of the script is as follows. Prepare a list of recipients in a CSV file, one recipient address per line. Prepare your message, plain text or html, in a separate file with the appropriate extension (.txt or .html). The run the script using the following arguments:

	-r path to CSV with recipient emails
	-b path to txt or html file with message body
	-s subject string
	-n from name; optional, defaults to New Music USA
	-a from email address; optional, defaults to info@newmusicusa.org
	-t timestamp (YYYY-MM-DD HH:MM:SS) in UTC of when to send messages

Some notes on using the bulk-send script:

* any trailing comma or lines in the csv will cause an error as it will read in as an empty email address and Mandrill will not send any emails
* watch out for exclamation points in comman line parameters, different shells will interpret them different
* the from email address has to be a newmusicusa.org address otherwise Mandrill won't send the emails
* for the message body tags work (strong, em, etc) but style info gets dropped (at least in gmail).