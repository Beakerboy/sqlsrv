CREATE NONCLUSTERED INDEX [_dta_index_locales_target_9_860370721__K1_K3_2_4] ON [dbo].[locales_target]
(
	[lid] ASC,
	[language] ASC
)
INCLUDE ( 	[translation],
	[customized]) WITH (SORT_IN_TEMPDB = OFF, DROP_EXISTING = OFF, ONLINE = OFF) ON [PRIMARY]