CREATE TABLE bucketvotes (
    userid      INT,  -- the userid of the user this vote applies to
    votekey     TEXT, -- the key for the vote (probably the page name)
    voteitemkey TEXT, -- the key for the item being voted on
    vote        INT,  -- the number of votes this user put on this item
    PRIMARY KEY(userid,votekey(40),voteitemkey(20))
);
