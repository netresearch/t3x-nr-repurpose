.. include:: /Includes.rst.txt

.. _adr-007:

=======================================================
ADR-007: Approval Step and Publishing Through a Webhook
=======================================================

:Status: Accepted
:Date: 2026-09-26
:Authors: Netresearch DTT GmbH

.. _adr-007-context:

Context
=======

Every artifact is AI-generated. Before a post goes out under the company's
name, a person should have looked at it and said yes. The social posts are the
one format this extension could put on a network itself, at a planned time.

Posting to LinkedIn, X or Instagram directly needs an app registration and an
OAuth grant per network and per account, and every network changes its API on
its own schedule. Which networks and whose accounts is not a decision the
extension can make.

.. _adr-007-decision:

Decision
========

**An approval state on every artifact.** ``review_status`` (open, approved,
rejected) with ``reviewed_by`` and ``reviewed_at`` on the artifact row. Users
whose groups grant the custom permission ``nrrepurpose:approve_artifacts`` (and
administrators) approve or reject a finished artifact in the result view. The
state has a reader: only an approved social post can be scheduled, and the
publishing command sends only approved posts.

**Scheduling on the artifact, publishing by a schedulable command.**
``publish_at`` and ``publish_status`` (scheduled, publishing, published, failed)
on the row. ``nr_repurpose:publish-due`` sends every approved post whose time
has come. It claims a post with one conditional update (scheduled → publishing)
before sending, so two runs at the same time cannot send it twice. A refused
post becomes failed with the channel's reason and stays failed until an editor
schedules it again.

**The channel is a webhook.** :php:`SocialPublisherInterface` has one
implementation, :php:`WebhookSocialPublisher`: an HTTP POST of the post as JSON
(platform, text, publishing time, source URL without user name and password,
``aiGenerated`` and the artifact's ``aiLabel``) to the URL in ``socialWebhookUrl``, optionally signed
with HMAC-SHA256 in ``X-Nr-Repurpose-Signature``. A scheduling tool, an
automation service or an own endpoint takes it from there. Without a URL the
command sends nothing and says how many posts are due.

**Regenerating a job starts over.** The generator deletes a job's artifacts
before it runs again, and with them their review and schedule.

.. _adr-007-consequences:

Consequences
============

●  No network credentials in the extension; the network is chosen where the
   webhook ends.

●  A post carries its AI label into the channel (``aiGenerated``,
   ``aiLabel``), so the receiving side can disclose it.

◐  Publishing is as punctual as the scheduler runs the command.

◐  The review of an image, a PDF or a podcast is recorded but has no reader
   yet; it documents the decision for the editor's team.

✕  Direct posting to a network needs its own :php:`SocialPublisherInterface`
   implementation with that network's credentials.
