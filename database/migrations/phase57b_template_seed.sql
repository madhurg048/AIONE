INSERT INTO `message_templates` (app_id, channel, purpose, name, dlt_template_id, sender_header, content, is_otp, is_active)
SELECT * FROM (
  SELECT NULL, 'sms', 'notification', 'Thank you', '1407160087125355930', 'PAYGin', 'Thank you for using Pay-G for your payment{#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Signup', '1407160087131991957', 'PAYGin', 'Thank you for signing up on Pay-G. Pay-G is the safest {#var#} fastest way to pay for all your transactions.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Transactions Details', '1407160087119295184', 'PAYGin', 'Thank you for using Pay-G for your payment. Hope it was a smooth experience for you. Your transaction with Pay-G was processed successfully. The transaction id is {#var#}Please get back to me if you need any further information.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Instant payment link', '1407162089334589064', 'PAYGin', 'Dear PayG Customer,
Click here to proceed to pay- {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Collecting Order', '1407162089446290803', 'PAYGin', 'Dear PayG Customer,
Click here to proceed to Pay: {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Easy Collect', '1407162089479239235', 'PAYGin', 'Dear Customer,
Your PayG Easy Collect payment link is - {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'POS Order', '1407162089490507082', 'PAYGin', 'Dear PayG Customer: 
please click here to see your Order Details- {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Prepaidcard Generated', '1407162089438886789', 'PAYGin', 'Dear Customer,
{#var#} has added a card for you. Your PayG Card Details are: 
Customer Name- {#var#}
Card Number- {#var#}
Pass Code- {#var#}
Expiry Date- {#var#}
Don''t share with anyone', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Add Balance To Prepaid Card', '1407162089599848502', 'PAYGin', 'Dear {#var#},
PayG Balance Updated On: {#var#}
Balance: {#var#}
Activity Amount: {#var#}
Please don''t reply to this message and don''t share with anyone.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Invoice', '1407162089365968355', 'PAYGin', 'Your PayG link to pay is: {#var#}
Customer Name :{#var#}
Invoice Amount : {#var#}
Customer Id : {#var#}
Please don''t reply to this message and don''t share with anyone.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Chargeback', '1407162089578756826', 'PAYGin', 'Dear PayG Merchant, 
The details of chargeback against Transaction Id :{#var#} are:-
Merchant KeyId:{#var#}
Transaction Amount:{#var#}
ChargeBack Amount:{#var#}
ChargeBack Date :{#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Transaction Successful', '1407163006286744852', 'PAYGin', 'Your Payment towards {#var#} of {#var#} is Successful. Please check receipt at {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Transaction Failed', '1407163006304108550', 'PAYGin', 'Your Payment towards {#var#} of {#var#} failed due to {#var#}.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Merchant UAT SMS template-1', '1407165632816711416', 'PAYGin', 'Hi {#var#}, A PayG transaction of {#var#} was done by {#var#} on your account.
Please click here to confirm authenticity-
https://uat.payg.in/payment/i?orderid={#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'reseller_user_registration', 'Reseller User Registration-1', '1407165632779944582', 'PAYGin', 'Your OTP for PayG Reseller User Registration is {#var#}. Valid for {#var#} minutes. Don''t reply to this message. Please don''t share with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'merchant_registration', 'Merchant Registration-1', '1407165632738580462', 'PAYGin', 'Your OTP for PayG Merchant Registration is {#var#}.Don''t reply to this message. Valid for {#var#} minutes. Please don''t share with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'reseller_registration', 'Reseller Registration-1', '1407165632772109399', 'PAYGin', 'Your OTP for PayG Reseller Registration is {#var#}. Valid for {#var#}minutes. Don''t share with anyone. Please don''t reply to this message.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Merchant Payout Security template-1', '1407165632823477525', 'PAYGin', 'Hi {#var#}, 
We noticed that a PayG transaction of {#var#} was made by you towards {#var#} from your account.
To confirm the transaction authenticity, please click here-
{#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'partner_registration', 'Partner Registration-1', '1407165632788630542', 'PAYGin', 'Your OTP for PayG Agent Registration is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message and don''t share with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'merchant_user_registration', 'Merchant User Registration-1', '1407165632811450079', 'PAYGin', 'Your OTP for PayG Merchant User Registration is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message and don''t share with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Customer Receiver Payout Security template-1', '1407165632832457140', 'PAYGin', 'Hi PayG {#var#}, a transaction of [amount] was received by you from {#var#} in your account. To confirm transaction authenticity, click here {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Merchant Production SMS Template-1', '1407165632837062225', 'PAYGPG', 'Hi {#var#}, A PayG transaction of {#var#} was done by {#var#} on your account.
Please click here to confirm authenticity-
https://payg.in/payment/i?orderid={#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'reseller_user_password_recovery', 'Reseller User Password Recovery', '1407165632968156846', 'PAYGin', '{#var#} is the OTP for your PayG Reseller User Password Recovery. Valid for {#var#} minutes. Please don''t reply to this message & never share this OTP with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Thank you Receipt-1', '1407165632877884448', 'PAYGPG', 'Thank you for using PayG for your payment 
 {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Merchant Activation-1', '1407165632899730392', 'PAYGPG', 'Congratulations! Your PayG Account with MID {#var#} is activated. Start transacting today: {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Customer Production SMS Template-1', '1407165632852283190', 'PAYGPG', 'Dear PayG Customer,a transaction of {#var#} on {#var#} is made by you. To confirm the transaction authenticity, please click here- https://payg.in/payment/i?orderid={#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Customer UAT SMS Template-1', '1407165632871556616', 'PAYGPG', 'Dear PayG Customer, transaction of {#var#} on {#var#} is initiated.To confirm the transaction authenticity, please click here-https://uat.payg.in/payment/i?orderid={#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Merchant UAT SMS Template -1', '1407165632859549189', 'PAYGPG', 'Hi {#var#}, A PayG transaction of {#var#} was done by {#var#} on your account.
Please click here to confirm authenticity-
https://uat.payg.in/payment/i?orderid={#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'reseller_password_recovery', 'Reseller Password Recovery', '1407165632958364503', 'PAYGin', '{#var#} is the OTP for your PayG Reseller Password Recovery. Valid for {#var#} minutes. Please don''t reply to this message & never share this OTP with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'xsilica_reseller_password_recovery', 'Reseller Password Recovery-1', '1407165719600987373', 'PAYGin', '{#var#} is the OTP for your PayG-Xsilica Reseller Password Recovery. Valid for {#var#} minutes. Please don''t reply to this message and never share this OTP with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'xsilica_merchant_password_recovery', 'Forget Password-2', '1407165719626052512', 'PAYGin', 'The OTP for your PayG-Xsilica Merchant Password Recovery is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message and never share this OTP with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'xsilica_transaction_otp', 'transaction otp', '1407166012330556869', 'PAYGin', 'Thanks for choosing Xsilica- PayG. {#var#}is OTP for the transaction initiated on PayG. The OTP is valid for the next minutes. DO NOT disclose it to anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Reminder Template', '1407167230401235342', 'PAYGin', 'This is a gentle reminder from PayG to return the Rs. {#var#}  you borrowed on {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Reminder link template', '1407167274075581303', 'PAYGin', 'This is a gentle reminder from PayG to return the Rs. {#var#} you borrowed on {#var#}. Click on the payment link for the payment {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'xsilica_customer_password_recovery_old', 'Customer Forget Password', '1407168752174157657', 'PAYGin', 'The OTP for your PayG-Xsilica Cusomer Password Recovery is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message & never share this OTP with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'xsilica_customer_password_recovery', 'Customer Forget Password 1', '1407168788113403138', 'PAYGin', 'The OTP for your PayG-Xsilica Customer Password Recovery is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message & never share this OTP with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Gentle Reminder Without Link', '1407168983687208454', 'PAYGin', 'This is a gentle reminder from PayG-Xsilica-{#var#} to return the Rs.{#var#} you borrowed on {#var#}.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'xsilica_pin_change', 'Forget Pin', '1407169052858762182', 'PAYGin', 'The OTP for your PayG-Xsilica PIN Change Recovery is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message and never share this OTP with anyone.', 1, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Gentle Reminder With Payment Link', '1407169088051157797', 'PAYGin', 'This is a gentle reminder from PayG-Xsilica - {#var#} to return the Rs.{#var#} you borrowed on {#var#}.Click on the Payment link for the Payment {#var#}.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'System User Password', '1407169452121422853', 'PAYGin', '{#var#} is the OTP for your PayG-Xsilica System User Password Recovery. Valid for 10 minutes. Do not reply to this message and never share OTP with anyone.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Customer Forget Password 2', '1407168856370959398', 'PAYGin', 'The OTP for your PayG-Xsilica Customer Password Recovery is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message and never share this OTP with anyone.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Bank Holidays', '1407170767375248328', 'PAYGin', 'Dear {#var#}, kindly note our upcoming Bank Holiday on {#var#}. Plan transactions accordingly to ensure smooth PayG services. Thank you for your cooperation.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'New Feature Launch', '1407170767398564120', 'PAYGin', 'Dear {#var#}, Exciting news! PayG introduces ''{#var#}'' to enhance your PayG experience. Elevate your payment experience today. Explore now! Thank you for choosing PayG.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Holiday Greetings', '1407170767385351249', 'PAYGin', 'Dear {#var#}, Happy {#var#}! PayG Wishes You joy and prosperity. May this festive season bring success to you and your business.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'System Downtime', '1407170843161461734', 'PAYGin', 'Dear Customer, PayG online channels will not be available on 09/01/2024 from 01:30am to 02:00am due to planned activity. We regret for the inconvenience', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Scheduled Deployment Intimation', '1407170749154044717', 'PAYGin', 'Due to maintenance downtime, you will be unable to transact with PayG on 29-11-23 from 2:15 AM to 3:15 AM IST. Regret any inconvenience.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'System-Downtime', '1407170964846224327', 'PAYGin', 'PayG online channels will not be available on {#var#} from {#var#} due to planned activity. We regret for the inconvenience.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Scheduled - Deployment Intimation', '1407170981138615691', 'PAYGin', 'Due to maintenance downtime, you will be unable to transact with PayG on {#var#} from {#var#}. Regret any inconvenience.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Verify Merchant Account', '1407171741843686074', 'PAYGin', 'Thanks for registering with PayG Merchant on behalf of Your MerchantKeyId is : {#var#}
Click on below link and  Verify your account by entering following OTP {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'New Merchant Onboarding', '1407171940950586499', 'PAYGin', 'Thanks for registering with PayG Merchant on behalf of Your MerchantKeyId is : {#var#}
Click on below link {#var#} and Verify your account by entering following OTP {#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Contact form', '1407174074931123438', 'PAYGin', 'Your OTP for PayG {#var#} to verify your contact number. Please enter this code to proceed. Please don''t reply to this message and don''t share with anyone', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'COLLECTING ORDER2', '1407174497177765274', 'PAYGin', 'Dear PayG Customer,
Click here to proceed to Pay: https://payg.in/payment/payment?{#var#}{#var#}', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'IRCTC TICKET BOOKING', '1007406448973829063', 'PAYGin', '{#var#},                          _x000D_
PNR:{#var#}, _x000D_
TRN:{#var#},		   _x000D_
DOJ:{#var#},		   _x000D_
FROM:{#var#},		   	_x000D_
CLS:{#var#},		   _x000D_
Boarding allowed from {#var#} only  _x000D_
For Enquiry/Complaint/Assistance, please dial 139 IR-CRIS_x000D_
Have a safe journey!_x000D_
- PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Refund Processed', '1007848007932842844', 'PAYGin', 'Dear Customer, _x000D_
  Refund of {#var#} against cancelled PNR {#var#} has been processed successfully._x000D_
   Amount will reflect in your account within 3-5 working days depending on bank policy._x000D_
  -PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Ticket Cancellation Confirmation', '1007931053577229672', 'PAYGin', 'Dear Customer, _x000D_
Your ticket with PNR {#var#} for Train {#var#} scheduled on {#var#} has been CANCELLED successfully. Refund of {#var#} will be processed to your original payment mode._x000D_
-PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'WL / RAC Status Update', '1007343331423555880', 'PAYGin', 'Dear Customer, _x000D_
   Current status for PNR {#var#}:{#var#}_x000D_
  Train: {#var#} _x000D_
  DOJ: {#var#}_x000D_
  Please check final status after chart preparation._x000D_
 -PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Partial Cancellation', '1007699642842767630', 'PAYGin', ' Dear Customer, _x000D_
   partial cancellation is successful for PNR {#var#}._x000D_
   Cancelled Passenger(s): {#var#}_x000D_
   Refund Amount:{#var#}_x000D_
   Remaining passengers are CONFIRMED._x000D_
   -PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Chart Prepared', '1007022372490578374', 'PAYGin', 'Dear Customer, _x000D_
  Chart has been prepared for PNR {#var#}_x000D_
  Coach/Berth: {#var#}_x000D_
  Train: {#var#} _x000D_
  DOJ: {#var#}_x000D_
  Have a pleasant journey!_x000D_
  -PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'OTP Safety', '1007222783829179311', 'PAYGin', 'PAYG User THODA DHYAN SE! OTP share karna risky hai. Bank, payment app ya merchant kabhi OTP, PIN, password nahi maangte. Kisi se bhi share na kare. Safe raho. ', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Train Cancelled by Railways', '1007674226835646992', 'PAYGin', 'Dear Customer, _x000D_
Train {#var#} scheduled on {#var#} has been CANCELLED by Railways.Full refund will be processed automatically for PNR {#var#}. No cancellation charges applicable. -PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Payment details Awareness', '1007447573949678126', 'PAYGin', 'PayGin Alert: THODA DHYAN SE! Fraudsters may call asking for OTP, PIN or password. Never share your banking details with anyone.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Links Awareness', '1007082897057484765', 'PAYGin', 'PayGin Security Alert: THODA DHYAN SE! Do not click unknown or suspicious links. Always verify links before entering payment details.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Failed Transaction', '1007767908439905021', 'PAYGin', 'Dear Customer, _x000D_
 Your booking transaction for Train {#var#} on {#var#} was unsuccessful. If amount was deducted, refund will be auto-processed within 5-7 working days. Ref ID: {#var#}_x000D_
-PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Rescheduled / Diverted Train', '1007714655204392730', 'PAYGin', 'Dear Customer, _x000D_
Train {#var#} for DOJ {#var#} has been RESCHEDULED by 120 minutes. Revised Departure: {#var#}.PNR: {#var#}_x000D_
  -PayG', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'QR Scan Se Savdhan', '1007000123095396789', 'PAYGin', 'PayGin Alert: Thoda dhyan se! Unknown QR code scan na karein. QR scan karne par paise debit ho sakte hai. Safe payment ke liye sirf trusted QR codes use kare.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Fake Offer Awareness', '1007294613648980228', 'PAYGin', 'PayGin Advisory: THODA DHYAN SE! Beware of fake offer messages asking for payment or details._x000D_
Always verify offers on the official PayGin app or website.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'App Awareness', '1007001688910367715', 'PAYGin', 'PayGin Alert: App download karne ke liye link? THODA DHYAN SE! Unknown links se app download na karein. Fraudsters aapka data aur paise chura sakte hain.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Merchant Authentication', '1007488787274181296', 'PAYGin', 'Dear Merchant, your PayG authentication keys will expire. Regenerate it via “Generate Authentication” from merchant login to avoid transaction disruption.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Cpv User login', '1007549186672603485', 'PAYGin', 'Your OTP for PayG CPV user verification is {#var#}. It is valid for {#var#} minutes. Please do not reply to this message and never share this OTP with anyone.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Customer Registration', '1007437562355121899', 'PAYGin', 'Your OTP for PayG Wallet User Registration is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message and don''t share with anyone.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Merchant Forgot password', '1007141663929296128', 'PAYGin', 'The OTP for your PayG Merchant Password Recovery is {#var#}. Valid for {#var#} minutes. Please don''t reply to this message & never share this OTP with anyone.', 0, 1 UNION ALL
  SELECT NULL, 'sms', 'notification', 'Payment Request', '1707163861311039621', 'MyRjCb|PAYGPG|PAYGin', 'Dear PayG Customer {#var#} has requested payment for INR {#var#}. Please use the below PayG link to pay {#var#}{#var#}{#var#}', 0, 1
) s
WHERE NOT EXISTS (SELECT 1 FROM `message_templates` WHERE channel='sms');
