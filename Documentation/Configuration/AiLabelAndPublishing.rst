.. SPDX-License-Identifier: CC-BY-4.0
.. SPDX-FileCopyrightText: Netresearch DTT GmbH

.. include:: /Includes.rst.txt

.. _configuration-output:

===========================
AI labelling and publishing
===========================

Five extension settings control the visible AI labels and the webhook
that publishes approved social posts.

.. _configuration-ai-label:

AI labelling
============

Every artifact carries a machine-readable AI marker, whatever the settings
below say: the ``aiLabel`` block of the artifact metadata, the markers embedded
in PNG and MP3 files, and the description of the stored file. See
:ref:`usage-ai-label` for what each artifact carries and :ref:`adr-005` for
why. Two extension settings (*Admin Tools > Settings > Extension
Configuration > nr_repurpose*) control the **visible** labels:

``aiLabelImages`` (default: on)
    Renders a small "AI-generated" label into the top corner of every
    Schaubild HTML render (variants ``html`` and ``html_bg``) and every story
    slide, in the language the artifact is written in. The full AI image
    (``ki_image``) comes straight from the image model and never passes the
    HTML renderer, so it carries the machine-readable marker only.

``aiLabelTexts`` (default: off)
    Appends the closing line "This text was created with AI." (German:
    "Dieser Text wurde mit KI erstellt.") to the copy-ready text of the
    executive summary, the FAQ, the social posts and the newsletter, in the
    text's language. It is off by default because editors usually paste these
    texts into a page, a newsletter tool or a social network that shows its own
    AI disclosure, where a second one in the text is noise. The social posts
    reserve the line's length inside their platform limit, so a post with the
    line still fits.

A setting that is missing — an installation whose extension configuration has
not been saved since the update — keeps its default.

.. _configuration-social:

Publishing social posts
=======================

Approved, scheduled social posts leave through a webhook (see :ref:`adr-007`).
Three extension settings:

``socialWebhookUrl`` (default: empty)
    The ``http`` or ``https`` URL that receives each due post by POST as JSON:
    ``artifactUid``, ``jobUid``, ``platform`` (``linkedin``, ``x``,
    ``instagram``), ``text``, ``publishAt`` (UTC, ISO 8601), ``sourceUrl``
    (the job's source URL without user name and password; query and fragment
    stay), ``aiGenerated`` (always ``true``) and ``aiLabel``. A 2xx answer marks the
    post published; anything else marks it failed with the status. Empty: no
    post is sent.

    The URL passes the same check as a source URL: its host must resolve only
    to public addresses, and the request connects to the addresses that were
    checked. A host in the local or internal network fails the post with
    ``Webhook URL refused: only http and https to a host on the public internet
    are allowed``. The request follows no redirect (a 3xx answer is a failure
    with its status) and has 15 seconds to complete, after which the post fails
    with ``Webhook not reachable``.

``socialWebhookSecretIdentifier`` (default: empty)
    The nr-vault identifier of the signing secret. With an identifier, each
    request carries
    ``X-Nr-Repurpose-Signature: sha256=<hex HMAC-SHA256 of the body>``, so the
    receiver can check that it comes from this installation. Store the secret
    in nr-vault through its backend module and enter the identifier here. On
    the command line, ``vendor/bin/typo3 vault:store`` works only with
    ``--as-provisioner`` and nr-vault's ``provisioningBeUserUid`` set, or with
    nr-vault's CLI access switched on. ``nr_repurpose:publish-due`` must be
    able to read the secret. With
    :confval:`technicalBeUserUid <technicalbeuseruid>` set, it reads as that
    backend user, who needs read access to the secret (administrator, owner,
    or a group the secret is shared with). With ``technicalBeUserUid`` at 0, it
    reads as the ``_cli_`` administrator when ``scheduler:run`` starts it; started
    directly from cron or a shell, it has no actor, and nr-vault refuses the
    read unless its CLI access is switched on. A secret that cannot be read fails the post
    with ``The webhook signing secret could not be read from nr-vault`` (the
    reason is in the TYPO3 log); an unknown identifier with ``The webhook
    signing secret was not found in nr-vault``.

``socialWebhookSecret`` (no longer used, leave empty)
    Held the signing secret itself in the system configuration. While it
    holds a value, no post is sent; the post fails with
    ``socialWebhookSecret is no longer read: …``. Move the secret into
    nr-vault, enter its identifier in ``socialWebhookSecretIdentifier`` and
    clear this field.

The command ``nr_repurpose:publish-due`` sends the posts whose time has come.
Add it as a task in the scheduler (it is schedulable) or run it from cron every
few minutes:

.. code-block:: bash

   vendor/bin/typo3 nr_repurpose:publish-due
