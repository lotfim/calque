=== Calque ===
Contributors: lotfim
Tags: booking, calendar, google, appointments
Requires at least: 6.8
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Mirror BookingPress appointments on a Google Calendar, including a one-shot import and live updates.

== Description ==

Calque copies BookingPress appointments onto one Google Calendar.

* Connect a Google account and choose the destination calendar.
* Import existing appointments once. Bookings already sent are skipped.
* New bookings are added automatically.
* Changes and cancellations update or remove the same Google event.

BookingPress Appointment Booking must be active. If it is missing, Calque links to https://www.bookingpressplugin.com/.

== Installation ==

1. Install and activate BookingPress Appointment Booking.
2. Upload the `calque` folder to `/wp-content/plugins/` and activate Calque.
3. Open Calque in the admin menu, save the Google Client ID and Client secret, then connect a calendar.

== Changelog ==

= 1.0.0 =
* Initial release.
